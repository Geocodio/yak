<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;

function queuedRun(ClaudeAccount $account, array $thread = [], TurnState $state = TurnState::WaitingForAccount): TaskRun
{
    $thread = Thread::factory()->create(['claude_account_id' => $account->id, 'model' => 'sonnet', ...$thread]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create([
        'yak_task_id' => $task->id, 'thread_id' => $thread->id, 'claude_account_id' => $account->id,
        'turn_state' => $state, 'prompt_uuid' => fake()->uuid(), 'prompt' => 'go', 'finished_at' => null,
    ]);
}

/**
 * Real AccountConcurrency; only the sandbox and runner commands are faked.
 *
 * @param  array<int, true>  $failingThreadIds  Threads whose sandbox wake throws.
 * @param  array<int, int>  $sent  Filled with thread ids in the order their turn is sent.
 */
function wireQueueCollaborators(array &$failingThreadIds, array &$sent): void
{
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturnUsing(function (Thread $thread) use (&$failingThreadIds) {
        if (isset($failingThreadIds[$thread->id])) {
            throw new RuntimeException('sandbox down');
        }

        return SandboxWake::ready();
    });
    $sandbox->shouldReceive('pauseIfIdle');
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('startRunner');
    $commands->shouldReceive('turn')->andReturnUsing(function (Thread $thread) use (&$sent) {
        $sent[] = $thread->id;
    });
    app()->instance(ThreadSandbox::class, $sandbox);
    app()->instance(ThreadCommands::class, $commands);
}

function holder(ClaudeAccount $account): TaskRun
{
    $run = TaskRun::factory()->create(['turn_state' => TurnState::Running, 'claude_account_id' => $account->id]);
    expect(app(AccountConcurrency::class)->acquire($account, $run))->toBeTrue();

    return $run;
}

it('does not let a closed thread waiter starve a new turn', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    $holder = holder($account);
    queuedRun($account, ['status' => ThreadStatus::Closed]);
    $next = queuedRun($account, [], TurnState::Pending);

    expect(app(AccountConcurrency::class)->acquire($account, $next))->toBeFalse();

    app(AccountConcurrency::class)->release($account, $holder);

    expect(app(AccountConcurrency::class)->acquire($account, $next->refresh()))->toBeTrue();
});

it('skips a waiter on a blocked thread without failing it', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    $holder = holder($account);
    $stale = queuedRun($account, ['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $next = queuedRun($account, [], TurnState::Pending);
    app(AccountConcurrency::class)->release($account, $holder);

    expect(app(AccountConcurrency::class)->acquire($account, $next))->toBeTrue()
        ->and($stale->refresh()->turn_state)->toBe(TurnState::WaitingForAccount);
});

it('fails a persistently broken waiter after five passes and then runs the next', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    $first = queuedRun($account);
    $second = queuedRun($account);
    $failing = [$first->thread_id => true];
    $sent = [];
    wireQueueCollaborators($failing, $sent);

    foreach (range(1, 4) as $pass) {
        app(ThreadTurns::class)->resumeWaiting();
        expect($first->refresh()->turn_state)->toBe(TurnState::WaitingForAccount)
            ->and($second->refresh()->turn_state)->toBe(TurnState::WaitingForAccount);
    }

    app(ThreadTurns::class)->resumeWaiting();

    expect($first->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($first->thread->refresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($second->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($sent)->toBe([$second->thread_id]);
});

it('starts healthy waiters oldest first as slots free up', function () {
    $account = ClaudeAccount::factory()->create(['max_concurrent_turns' => 1]);
    $holder = holder($account);
    $runs = [queuedRun($account), queuedRun($account), queuedRun($account)];
    $failing = [];
    $sent = [];
    wireQueueCollaborators($failing, $sent);
    $queue = app(AccountConcurrency::class);

    app(ThreadTurns::class)->resumeWaiting();
    expect($sent)->toBe([]);

    $queue->release($account, $holder);
    app(ThreadTurns::class)->resumeWaiting();
    expect($sent)->toBe([$runs[0]->thread_id]);

    $queue->release($account, $runs[0]->refresh());
    app(ThreadTurns::class)->resumeWaiting();
    expect($sent)->toBe([$runs[0]->thread_id, $runs[1]->thread_id]);

    $queue->release($account, $runs[1]->refresh());
    app(ThreadTurns::class)->resumeWaiting();
    expect($sent)->toBe(array_map(fn (TaskRun $run): int => $run->thread_id, $runs));
});
