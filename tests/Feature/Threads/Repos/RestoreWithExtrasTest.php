<?php

use App\Enums\RepositorySetupStatus;
use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\SandboxRestorer;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
});

function restorableThread(): Thread
{
    return Thread::factory()->create([
        'repo' => 'acme/app',
        'branch' => 'yak/thread-branch',
        'sandbox_state' => ThreadSandboxState::Destroyed,
        'runner_state' => RunnerState::None,
    ]);
}

function restoreStepStatuses(Thread $thread): array
{
    return $thread->restoreSteps()->get()->mapWithKeys(fn ($step) => [$step->step->value => $step->status])->all();
}

test('the extras step runs after refresh triggers and before session files', function () {
    $names = array_map(fn (RestoreStepName $name): string => $name->value, RestoreStepName::cases());

    expect(array_search('set_up_extra_repositories', $names))->toBe(array_search('run_refresh_triggers', $names) + 1)
        ->and(array_search('restore_session_files', $names))->toBe(array_search('set_up_extra_repositories', $names) + 1);
});

test('a restore with extras rebuilds them and resets their setup status', function () {
    $thread = restorableThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);
    $changes = ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', 'setup_status' => RepositorySetupStatus::Failed, 'setup_error' => 'old']);
    $context = ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/docs']);
    $sandbox = app(SandboxManager::class);

    // Primary checkout (2), setup check (1), refresh config (1), then the extras clone output.
    foreach ([Process::result(), Process::result(), Process::result(exitCode: 1), Process::result(exitCode: 1)] as $result) {
        $sandbox->queueExecResult($result);
    }
    $sandbox->queueExecResult(Process::result("@@YAK_BEGIN 0\n\n@@YAK_EXIT 0 0 1\n@@YAK_BEGIN 1\n\n@@YAK_EXIT 1 0 1\n"));
    $sandbox->queueExecResult(Process::result("@@YAK_INSPECT 0\n@@YAK_HEAD\naaa\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n@@YAK_INSPECT 1\n@@YAK_HEAD\nbbb\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n"));

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and(restoreStepStatuses($thread)[RestoreStepName::SetUpExtraRepositories->value])->toBe(RestoreStepStatus::Succeeded)
        ->and($changes->fresh()->setup_status)->toBe(RepositorySetupStatus::Ready)
        ->and($changes->fresh()->setup_error)->toBeNull()
        ->and($context->fresh()->setup_status)->toBe(RepositorySetupStatus::Ready)
        ->and($changes->fresh()->branch)->toBe('yak/thread-branch');

    $clones = collect($sandbox->calls)->where('operation', 'exec')->pluck('arguments.stdin')->filter(fn ($stdin) => str_contains((string) $stdin, 'git clone'))->first();
    expect($clones)->toContain('acme/lib')->toContain('acme/docs')->not->toContain('acme/app');
});

test('a failing extra does not block the restore', function () {
    $thread = restorableThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);
    $lib = ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib']);
    $sandbox = app(SandboxManager::class);

    foreach ([Process::result(), Process::result(), Process::result(exitCode: 1), Process::result(exitCode: 1)] as $result) {
        $sandbox->queueExecResult($result);
    }
    $sandbox->queueExecResult(Process::result("@@YAK_BEGIN 0\nfatal: not found\n@@YAK_EXIT 0 128 1\n"));

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and($thread->fresh()->blocked_reason)->not->toBe(ThreadBlockReason::RestoreFailed)
        ->and($lib->fresh()->setup_status)->toBe(RepositorySetupStatus::Failed)
        ->and(restoreStepStatuses($thread)[RestoreStepName::RestoreSessionFiles->value])->toBe(RestoreStepStatus::Succeeded);
});

test('without extras the step is skipped and no extra exec runs', function () {
    $thread = restorableThread();
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and(restoreStepStatuses($thread)[RestoreStepName::SetUpExtraRepositories->value])->toBe(RestoreStepStatus::Skipped);

    $bashScripts = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->filter(fn ($call) => ($call['arguments']['command'] ?? []) === ['bash', '-s']);
    expect($bashScripts)->toHaveCount(0);
});
