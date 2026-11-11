<?php

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\SandboxRestorer;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\WipPreserver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSessionFileSync;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    $this->sync = new FakeSessionFileSync;
    app()->instance(SessionFileSync::class, $this->sync);
    $this->restoreTempDir = null;
});

afterEach(function () {
    if ($this->restoreTempDir !== null) {
        File::deleteDirectory($this->restoreTempDir);
    }
});

function destroyedThread(array $attributes = []): Thread
{
    return Thread::factory()->create([
        'repo' => 'acme/app',
        'branch' => 'yak/thread-branch',
        'sandbox_state' => ThreadSandboxState::Destroyed,
        'runner_state' => RunnerState::None,
        ...$attributes,
    ]);
}

/** Queues successful results for the two checkout commands and the setup.sh presence check. */
function queueThroughSetupCheck(FakeSandboxManager $sandbox, int $setupCheckExit = 0): void
{
    $sandbox->queueExecResult(Process::result());
    $sandbox->queueExecResult(Process::result());
    $sandbox->queueExecResult(Process::result(exitCode: $setupCheckExit));
}

function stepStatuses(Thread $thread): array
{
    return $thread->restoreSteps()->get()->mapWithKeys(fn ($step) => [$step->step->value => $step->status])->all();
}

function useLocalGit($test): LocalGitSandboxManager
{
    $test->restoreTempDir = sys_get_temp_dir() . '/yak-restore-' . uniqid();
    mkdir($test->restoreTempDir, 0777, true);
    $git = LocalGitSandboxManager::withRepository($test->restoreTempDir);
    app()->instance(SandboxManager::class, $git);

    return $git;
}

test('seven steps are recorded in order', function () {
    $thread = destroyedThread();

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue();

    $steps = $thread->restoreSteps()->get();
    expect($steps->pluck('step')->all())->toBe(RestoreStepName::cases())
        ->and($steps->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($steps->pluck('status')->all())->toBe([
            RestoreStepStatus::Succeeded, RestoreStepStatus::Succeeded, RestoreStepStatus::Succeeded,
            RestoreStepStatus::Skipped, RestoreStepStatus::Skipped, RestoreStepStatus::Succeeded, RestoreStepStatus::Succeeded,
        ])
        ->and($steps->every(fn ($step) => $step->started_at !== null && $step->finished_at !== null))->toBeTrue()
        ->and($this->sync->restored)->toBe([$thread->id])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

test('restore checks out the branch and sees the wip commit', function () {
    $git = useLocalGit($this);
    $thread = destroyedThread(['sandbox_state' => ThreadSandboxState::Running, 'branch' => $git->branch]);
    file_put_contents("{$git->workspace}/work.txt", "in progress\n");
    app(WipPreserver::class)->preserve($thread);

    $git->destroy($thread);
    $thread->update(['sandbox_state' => ThreadSandboxState::Destroyed]);
    $git->git(['checkout', 'main'], $git->workspace);
    $git->git(['branch', '-D', $git->branch], $git->workspace);
    expect(file_exists("{$git->workspace}/work.txt"))->toBeFalse();

    expect(app(SandboxRestorer::class)->restore($thread->fresh()))->toBeTrue()
        ->and(file_get_contents("{$git->workspace}/work.txt"))->toBe("in progress\n")
        ->and($git->git(['branch', '--show-current'], $git->workspace))->toBe($git->branch);
});

test('a branch that was never pushed starts from the template checkout', function () {
    $git = useLocalGit($this);
    $thread = destroyedThread(['branch' => 'yak/never-pushed']);

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and($git->git(['branch', '--show-current'], $git->workspace))->toBe('yak/never-pushed');
});

test('setup script runs with build credentials in env only', function () {
    Repository::factory()->create(['slug' => 'acme/app', 'build_credentials' => ['NOVA_TOKEN' => 'secret-value']]);
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);

    app(SandboxRestorer::class)->restore($thread);

    $exec = array_column(array_filter($sandbox->calls, fn (array $call): bool => $call['operation'] === 'exec'), 'arguments');
    $withEnv = array_values(array_filter($exec, fn (array $call): bool => $call['env'] !== []));

    expect($withEnv)->toHaveCount(1)
        ->and($withEnv[0]['command'])->toBe(['bash', '.yak/setup.sh'])
        ->and($withEnv[0]['env'])->toBe(['NOVA_TOKEN' => 'secret-value']);
});

test('a repo without a setup script skips that step', function () {
    $thread = destroyedThread();
    queueThroughSetupCheck(app(SandboxManager::class), setupCheckExit: 1);

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and(stepStatuses($thread)[RestoreStepName::RunSetupScript->value])->toBe(RestoreStepStatus::Skipped);
});

test('a failed setup script marks step three failed and stops', function () {
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);
    queueThroughSetupCheck($sandbox);
    $sandbox->queueExecResult(Process::result('npm ERR', 'boom', 2));

    expect(app(SandboxRestorer::class)->restore($thread))->toBeFalse();

    $steps = $thread->restoreSteps()->get();
    expect($steps->pluck('status')->all())->toBe([
        RestoreStepStatus::Succeeded, RestoreStepStatus::Succeeded, RestoreStepStatus::Failed,
        RestoreStepStatus::Pending, RestoreStepStatus::Pending, RestoreStepStatus::Pending, RestoreStepStatus::Pending,
    ])
        ->and($steps[2]->error)->toContain('exit 2')
        ->and($steps[2]->output)->toContain('boom')
        ->and($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::RestoreFailed);
});

test('retry resumes at the failed step without recreating the sandbox', function () {
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);
    queueThroughSetupCheck($sandbox);
    $sandbox->queueExecResult(Process::result('', 'boom', 2));
    app(SandboxRestorer::class)->restore($thread);

    $thread->refresh();
    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue();

    $operations = array_column($sandbox->calls, 'operation');
    expect(array_count_values($operations)['create'])->toBe(1)
        ->and(array_count_values($operations)['start'])->toBe(1)
        ->and($thread->restoreSteps()->count())->toBe(7);
});

test('retry success clears restore failed', function () {
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);
    queueThroughSetupCheck($sandbox);
    $sandbox->queueExecResult(Process::result('', 'boom', 2));
    app(SandboxRestorer::class)->restore($thread);

    app(SandboxRestorer::class)->restore($thread->fresh());

    $fresh = $thread->fresh();
    expect($fresh->blocked_reason)->toBeNull()->and($fresh->blocked_at)->toBeNull()
        ->and(array_unique(array_map(fn ($status) => $status->value, stepStatuses($fresh))))->not->toContain('failed', 'pending');
});

test('a finished restore starts over the next time', function () {
    $thread = destroyedThread();
    app(SandboxRestorer::class)->restore($thread);
    app(SandboxManager::class)->destroy($thread);
    $thread->update(['sandbox_state' => ThreadSandboxState::Destroyed]);

    app(SandboxRestorer::class)->restore($thread->fresh());

    expect(array_column(app(SandboxManager::class)->calls, 'operation'))->toContain('create')
        ->and(array_count_values(array_column(app(SandboxManager::class)->calls, 'operation'))['create'])->toBe(2)
        ->and($thread->restoreSteps()->count())->toBe(7);
});

test('local path symlink is created when a local path exists', function () {
    $git = useLocalGit($this);
    $link = "{$this->restoreTempDir}/mapped/projects/app";
    $thread = destroyedThread(['branch' => $git->branch, 'local_repo_path' => $link]);

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and(is_link($link))->toBeTrue()
        ->and(readlink($link))->toBe('/workspace');
});

test('environment note is written last', function () {
    $thread = destroyedThread(['wip_commit_sha' => str_repeat('a', 40)]);
    $sandbox = app(SandboxManager::class);

    app(SandboxRestorer::class)->restore($thread);

    $exec = array_values(array_column(array_filter($sandbox->calls, fn (array $call): bool => $call['operation'] === 'exec'), 'arguments'));
    $last = end($exec);

    expect($last['command'][0])->toBe('sh')
        ->and($last['stdin'])->toContain('Restored uncommitted work from the last WIP commit');
});

test('step output never contains build credential values', function () {
    Repository::factory()->create(['slug' => 'acme/app', 'build_credentials' => ['NOVA_TOKEN' => 'secret-value']]);
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);
    queueThroughSetupCheck($sandbox);
    $sandbox->queueExecResult(Process::result('using secret-value ok', 'https://user:secret-value@example.com failed', 3));

    app(SandboxRestorer::class)->restore($thread);

    $setup = $thread->restoreSteps()->get()[2];
    expect($setup->output)->toContain('[redacted]')->not->toContain('secret-value')
        ->and($setup->error)->not->toContain('secret-value');
});

test('step output is truncated to 64 KB', function () {
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);
    queueThroughSetupCheck($sandbox);
    $sandbox->queueExecResult(Process::result(str_repeat('x', 200000)));

    app(SandboxRestorer::class)->restore($thread);

    expect(strlen($thread->restoreSteps()->get()[2]->output))->toBeLessThanOrEqual(65536);
});

test('ensure awake for a destroyed sandbox restores then reports ready', function () {
    $thread = destroyedThread();

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->ready)->toBeTrue()->and($wake->restoring)->toBeFalse()
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and($thread->restoreSteps()->count())->toBe(7);
});

test('ensure awake reports a failed restore as neither ready nor restoring', function () {
    $thread = destroyedThread();
    $sandbox = app(SandboxManager::class);
    queueThroughSetupCheck($sandbox);
    $sandbox->queueExecResult(Process::result('', 'boom', 2));

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->ready)->toBeFalse()->and($wake->restoring)->toBeFalse()->and($wake->waitingForSlot)->toBeFalse();

    expect(app(ThreadSandbox::class)->restore($thread->fresh()))->toBeTrue();
});

test('ensure awake during a restore reports restoring', function () {
    $thread = destroyedThread();
    $lock = Cache::lock("thread-sandbox:{$thread->id}", 60);
    $lock->get();

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->restoring)->toBeTrue()->and($wake->ready)->toBeFalse()
        ->and(app(SandboxManager::class)->calls)->toBe([]);

    $lock->release();
});
