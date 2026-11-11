<?php

use App\DataTransferObjects\RepositorySetupResult;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Events\ThreadRepositoryChanged;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Datasets\DatasetClones;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

function extrasThread(): Thread
{
    return Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app', 'branch' => 'yak/thread-branch']);
}

function extraRow(Thread $thread, string $repo, string $state = 'changes'): ThreadRepository
{
    return ThreadRepository::factory()->{$state}()->create([
        'thread_id' => $thread->id,
        'repo' => $repo,
        'setup_status' => RepositorySetupStatus::Pending,
    ]);
}

/** Output of the clone script for the given [exit, log] pairs. */
function cloneOutput(array $repos): string
{
    $output = '';
    foreach ($repos as $index => [$exit, $log]) {
        $output .= "@@YAK_BEGIN {$index}\n{$log}\n@@YAK_EXIT {$index} {$exit} 3\n";
    }

    return $output;
}

/** Output of the inspect script: [hasSetup, head, config, files] per repo. */
function inspectOutput(array $repos): string
{
    $output = '';
    foreach ($repos as $index => [$hasSetup, $head, $config, $files]) {
        $output .= "@@YAK_INSPECT {$index}\n" . ($hasSetup ? "@@YAK_SETUP\n" : '') . "@@YAK_HEAD\n{$head}\n@@YAK_CONFIG\n{$config}\n@@YAK_FILES\n{$files}\n@@YAK_END\n";
    }

    return $output;
}

function queueExtras(string ...$outputs): void
{
    foreach ($outputs as $output) {
        app(SandboxManager::class)->queueExecResult(Process::result($output));
    }
}

test('marks one repo failed and keeps the other ready when two setup scripts run in parallel', function () {
    $thread = extrasThread();
    $first = extraRow($thread, 'acme/one');
    $second = extraRow($thread, 'acme/two');

    queueExtras(
        cloneOutput([[0, ''], [0, '']]),
        inspectOutput([[true, 'aaa', '', ''], [true, 'bbb', '', '']]),
        "@@YAK_BEGIN 0\ncontainer name already in use\n@@YAK_EXIT 0 1 2\n@@YAK_BEGIN 1\nok\n@@YAK_EXIT 1 0 2\n",
    );

    $results = app(ExtraRepositorySetup::class)->setUpAll($thread, collect([$first, $second]));

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBeInstanceOf(RepositorySetupResult::class)
        ->and($results[0]->status)->toBe(RepositorySetupStatus::Failed)
        ->and($results[0]->error)->toBe('container name already in use (exit 1)')
        ->and($results[1]->status)->toBe(RepositorySetupStatus::Ready)
        ->and($first->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed)
        ->and($first->fresh()->setup_error)->toBe('container name already in use (exit 1)')
        ->and($second->fresh()->setup_status)->toBe(RepositorySetupStatus::Ready)
        ->and($second->fresh()->last_refresh_commit)->toBe('bbb');
});

test('the clone script skips only the clone for an existing checkout and still checks out the thread branch', function () {
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');

    queueExtras(cloneOutput([[0, '']]), inspectOutput([[false, 'aaa', '', '']]));

    app(ExtraRepositorySetup::class)->setUp($thread, $row);

    $script = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->values()[0]['arguments']['stdin'];
    expect($script)->toContain('.git ] || git clone')
        ->not->toContain('exit 0')
        ->toContain("git checkout -B 'yak/thread-branch'");
});

test('a failed clone keeps the row as failed and skips setup', function () {
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');

    queueExtras(cloneOutput([[128, 'fatal: repository not found']]), inspectOutput([]));

    $result = app(ExtraRepositorySetup::class)->setUp($thread, $row);

    expect($result->status)->toBe(RepositorySetupStatus::Failed)
        ->and($row->fresh()->setup_error)->toBe('fatal: repository not found (exit 128)');
});

test('scrubs build credentials from stored output', function () {
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');
    Repository::factory()->create(['slug' => 'acme/one', 'build_credentials' => ['NPM_TOKEN' => 'sekret-value']]);

    queueExtras(
        cloneOutput([[0, 'cloning from https://x-token:abc123@github.com/acme/one.git']]),
        inspectOutput([[true, 'aaa', '', '']]),
        "@@YAK_BEGIN 0\nusing sekret-value to log in\n@@YAK_EXIT 0 1 1\n",
    );

    app(ExtraRepositorySetup::class)->setUp($thread, $row);

    $fresh = $row->fresh();
    $stored = $fresh->setup_output . $fresh->setup_error;
    expect($stored)->not->toContain('sekret-value')->not->toContain('abc123')
        ->and($stored)->toContain('[redacted]');

    $setupCall = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->values()[2]['arguments'];
    expect($setupCall['env'])->toBe([])
        ->and($setupCall['stdin'])->toContain('NPM_TOKEN=')
        ->and(collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->values()[0]['arguments']['stdin'])->not->toContain('sekret-value');
});

test('never attaches a dataset to an extra repository', function () {
    $this->mock(DatasetClones::class)->shouldNotReceive('attach');
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');

    queueExtras(cloneOutput([[0, '']]), inspectOutput([[false, 'aaa', '', '']]));

    expect(app(ExtraRepositorySetup::class)->setUp($thread, $row)->status)->toBe(RepositorySetupStatus::Ready);
});

test('skips setup and refresh when the repo has no yak files', function () {
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');

    queueExtras(cloneOutput([[0, '']]), inspectOutput([[false, 'aaa', '', "README.md\n"]]));

    $result = app(ExtraRepositorySetup::class)->setUp($thread, $row);

    $execs = collect(app(SandboxManager::class)->calls)->where('operation', 'exec');
    expect($execs)->toHaveCount(2)
        ->and($result->status)->toBe(RepositorySetupStatus::Ready);
});

test('runs matching refresh commands and records the head commit', function () {
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');

    queueExtras(
        cloneOutput([[0, '']]),
        inspectOutput([[false, 'abc', "refresh:\n  composer.lock: composer install\n  package-lock.json: npm ci\n", "composer.lock\nREADME.md\n"]]),
        "@@YAK_BEGIN 0\n$ composer install\n@@YAK_EXIT 0 0 1\n",
    );

    app(ExtraRepositorySetup::class)->setUp($thread, $row);

    $script = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->values()[2]['arguments']['stdin'];
    expect($script)->toContain('composer install')->not->toContain('npm ci')
        ->and($row->fresh()->last_refresh_commit)->toBe('abc');
});

test('fires a change event for each status transition', function () {
    Event::fake([ThreadRepositoryChanged::class]);
    $thread = extrasThread();
    $row = extraRow($thread, 'acme/one');

    queueExtras(cloneOutput([[0, '']]), inspectOutput([[false, 'aaa', '', '']]));

    app(ExtraRepositorySetup::class)->setUp($thread, $row);

    Event::assertDispatchedTimes(ThreadRepositoryChanged::class, 3);
});
