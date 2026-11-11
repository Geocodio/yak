<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadStatus;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnFailed;
use App\Models\TaskRun;
use App\Models\ThreadTurnMessage;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\Threads\ThreadTurns;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

function waitingRun(TurnState $state, string $prompt): TaskRun
{
    [$thread, $task, $account] = threadWithTask();

    return TaskRun::factory()->create([
        'yak_task_id' => $task->id, 'thread_id' => $thread->id, 'claude_account_id' => $account->id,
        'turn_state' => $state, 'prompt_uuid' => fake()->uuid(), 'prompt' => $prompt, 'finished_at' => null,
    ]);
}

it('starts a waiting run once the account slot frees up', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);
});

it('leaves a still-waiting run alone', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    $fakes = fakeTurnCollaborators(slotFree: false);
    $fakes['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::WaitingForAccount);
});

it('does not take a second account slot for a run waiting on the sandbox', function () {
    $run = waitingRun(TurnState::WaitingForSandbox, 'go');
    $run->thread->forceFill(['blocked_reason' => ThreadBlockReason::WaitingForSandbox])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['concurrency']->shouldNotReceive('acquire');
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($run->thread->refresh()->blocked_reason)->toBeNull();
});

it('resumes the oldest waiting run first', function () {
    $first = waitingRun(TurnState::WaitingForAccount, 'first');
    $second = waitingRun(TurnState::WaitingForAccount, 'second');
    $fakes = fakeTurnCollaborators();
    $order = [];
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->andReturnUsing(function ($thread, $uuid, $text) use (&$order) {
        $order[] = $text;
    });

    app(ThreadTurns::class)->resumeWaiting();

    expect($order)->toBe(['first', 'second']);
});

it('moves a run waiting for an account on to the sandbox wait', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    fakeTurnCollaborators(wake: SandboxWake::restoring())['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::WaitingForSandbox);
});

it('starts a pending run once its thread is unblocked', function () {
    $run = waitingRun(TurnState::Pending, 'go');
    $run->forceFill(['started_at' => now()->subMinute()])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);
});

it('leaves a pending run on a blocked thread alone', function () {
    $run = waitingRun(TurnState::Pending, 'go');
    $run->forceFill(['started_at' => now()->subMinute()])->save();
    $run->thread->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit])->save();
    fakeTurnCollaborators()['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Pending);
});

it('leaves a fresh pending run to start()', function () {
    $run = waitingRun(TurnState::Pending, 'go');
    $run->forceFill(['started_at' => now()])->save();
    fakeTurnCollaborators()['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Pending);
});

function lockedWaitingRun(TurnState $state = TurnState::WaitingForAccount): TaskRun
{
    $run = waitingRun($state, 'go');
    $run->thread->forceFill([
        'lock_holder' => ThreadLockHolder::Sandbox, 'lock_turn_id' => (string) $run->id,
        'blocked_reason' => ThreadBlockReason::WaitingForAccount, 'blocked_at' => now(),
    ])->save();
    ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => (string) $run->prompt_uuid, 'kind' => TurnMessageKind::Prompt, 'state' => TurnMessageState::Sent]);

    return $run;
}

it('fails a waiter through the turn finisher and frees the thread', function () {
    Event::fake([TurnFailed::class]);
    $run = lockedWaitingRun();
    fakeTurnCollaborators();

    app(ThreadTurns::class)->failWaiter($run);

    $thread = $run->thread->refresh();
    expect($run->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($thread->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($thread->lock_turn_id)->toBeNull()
        ->and($thread->blocked_reason)->toBeNull()
        ->and(ThreadTurnMessage::query()->where('task_run_id', $run->id)->value('state'))->toBe(TurnMessageState::Cancelled);
    Event::assertDispatched(TurnFailed::class);
});

it('lets a new turn start after a waiter was failed', function () {
    $run = lockedWaitingRun();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->failWaiter($run);

    $next = app(ThreadTurns::class)->start($run->task, 'again', null, null);

    expect($next->turn_state)->toBe(TurnState::Running);
});

it('releases the slot of a failed waiter that waited on the sandbox', function () {
    $run = lockedWaitingRun(TurnState::WaitingForSandbox);
    $account = $run->claudeAccount;
    $run->forceFill(['account_slot_held_at' => now()])->save();
    $concurrency = app(AccountConcurrency::class);
    expect($concurrency->heldCount($account))->toBe(1);

    app(ThreadTurns::class)->failWaiter($run);

    expect($concurrency->heldCount($account))->toBe(0);
});

it('resets the lock of a failed waiter on a closed thread', function () {
    $run = lockedWaitingRun();
    $run->thread->forceFill(['status' => ThreadStatus::Closed])->save();

    app(ThreadTurns::class)->failWaiter($run);

    expect($run->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($run->thread->refresh()->lock_holder)->toBe(ThreadLockHolder::None);
});

it('fails a waiter whose thread is gone without an event and frees its slot', function () {
    Event::fake([TurnFailed::class]);
    $run = lockedWaitingRun(TurnState::WaitingForSandbox);
    $run->forceFill(['account_slot_held_at' => now()])->save();
    Schema::disableForeignKeyConstraints();
    $run->thread->delete();
    Schema::enableForeignKeyConstraints();
    $run->forceFill(['thread_id' => $run->thread_id])->refresh();

    app(ThreadTurns::class)->failWaiter($run);

    expect($run->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($run->account_slot_held_at)->toBeNull();
    Event::assertNotDispatched(TurnFailed::class);
});

it('retries a waiter whose advance throws and fails it on the fifth pass', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    $fakes = fakeTurnCollaborators();
    $fakes['sandbox']->shouldReceive('ensureAwake')->andThrow(new RuntimeException('boom'));

    foreach (range(1, 4) as $pass) {
        app(ThreadTurns::class)->resumeWaiting();
        expect($run->refresh()->turn_state)->toBe(TurnState::WaitingForAccount);
    }

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($run->thread->refresh()->lock_holder)->toBe(ThreadLockHolder::None);
});

it('resets the failure counter when the waiter advances', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    Cache::put("thread-turns:advance-failures:{$run->id}", 4, now()->addDay());
    $fakes = fakeTurnCollaborators(slotFree: false);

    app(ThreadTurns::class)->resumeWaiting();

    expect(Cache::has("thread-turns:advance-failures:{$run->id}"))->toBeFalse()
        ->and($run->refresh()->turn_state)->toBe(TurnState::WaitingForAccount);
});

it('fails a waiter at once when its account is gone', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    fakeTurnCollaborators()['sandbox']->shouldReceive('ensureAwake')->andThrow(new ModelNotFoundException);

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Failed);
});

it('starts the next waiter in the same pass after the first fails permanently', function () {
    $first = waitingRun(TurnState::WaitingForAccount, 'first');
    $second = waitingRun(TurnState::WaitingForAccount, 'second');
    $fakes = fakeTurnCollaborators();
    $fakes['concurrency']->shouldReceive('releaseRun');
    $fakes['concurrency']->shouldReceive('acquire')->andReturnUsing(function ($account, $run) use ($first) {
        if ($run->id === $first->id) {
            throw new ModelNotFoundException;
        }

        return true;
    });
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($first->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($second->refresh()->turn_state)->toBe(TurnState::Running);
});
