<?php

use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnMessageKind;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\ThreadTurnMessage;
use App\Services\ClaudeAccounts\ResolvedAccount;
use App\Services\Threads\SandboxWake;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

it('starts a runner and sends the turn', function () {
    [$thread, $task] = threadWithTask();
    $fakes = fakeTurnCollaborators();
    $sent = [];
    $fakes['commands']->shouldReceive('startRunner')->once()->andReturnUsing(function () use (&$sent) {
        $sent[] = 'start_runner';
    });
    $fakes['commands']->shouldReceive('turn')->once()->andReturnUsing(function ($thread, $uuid, $text) use (&$sent) {
        $sent[] = ['turn', $text];
    });

    $run = app(ThreadTurns::class)->start($task, 'fix the bug', null, null);

    expect($sent)->toBe(['start_runner', ['turn', 'fix the bug']])
        ->and($run->turn_state)->toBe(TurnState::Running)
        ->and($run->thread_id)->toBe($thread->id)
        ->and($thread->refresh()->lock_holder)->toBe(ThreadLockHolder::Sandbox)
        ->and($thread->lock_turn_id)->toBe((string) $run->id)
        ->and(ThreadTurnMessage::query()->where('task_run_id', $run->id)->first()->kind)->toBe(TurnMessageKind::Prompt);
});

it('only sends a turn when the runner is idle and sets the model when it changed', function () {
    [$thread, $task] = threadWithTask();
    $thread->forceFill(['runner_state' => RunnerState::Idle, 'runner_model' => 'sonnet'])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldNotReceive('startRunner');
    $fakes['commands']->shouldReceive('setModel')->once()->with(Mockery::any(), 'opus');
    $fakes['commands']->shouldReceive('turn')->once();

    $run = app(ThreadTurns::class)->start($task, 'next', null, 'opus');

    expect($run->turn_state)->toBe(TurnState::Running)->and($run->model)->toBe('opus');
});

it('throws ThreadBusy while a turn is active', function () {
    [, $task] = threadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn');

    app(ThreadTurns::class)->start($task, 'one', null, null);

    expect(fn () => app(ThreadTurns::class)->start($task, 'two', null, null))->toThrow(ThreadBusy::class);
});

it('waits for an account and records the reason', function () {
    [$thread, $task] = threadWithTask();
    $fakes = fakeTurnCollaborators(slotFree: false);
    $fakes['commands']->shouldNotReceive('startRunner');
    $fakes['commands']->shouldNotReceive('turn');

    $run = app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($run->turn_state)->toBe(TurnState::WaitingForAccount)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForAccount);
});

it('waits for a sandbox slot', function () {
    [$thread, $task] = threadWithTask();
    $fakes = fakeTurnCollaborators(wake: new SandboxWake(ready: false, waitingForSlot: true));
    $fakes['commands']->shouldNotReceive('turn');

    $run = app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($run->turn_state)->toBe(TurnState::WaitingForSandbox)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForSandbox);
});

it('keeps the thread account instead of resolving a new one', function () {
    [$thread, $task, $account] = threadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['resolver']->shouldNotReceive('forNewThread');
    $fakes['commands']->shouldReceive('startRunner', 'turn');

    $run = app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($run->claude_account_id)->toBe($account->id);
});

it('resolves and stores an account for a thread without one', function () {
    [$thread, $task] = threadWithTask();
    $thread->forceFill(['claude_account_id' => null, 'model' => null])->save();
    $account = ClaudeAccount::factory()->create();
    $fakes = fakeTurnCollaborators();
    $fakes['resolver']->shouldReceive('forNewThread')->once()->andReturn(new ResolvedAccount($account, 'opus'));
    $fakes['commands']->shouldReceive('startRunner', 'turn');

    app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($thread->refresh()->claude_account_id)->toBe($account->id)->and($thread->model)->toBe('opus');
});

it('keeps the turn pending on a blocked account', function () {
    [$thread, $task] = threadWithTask();
    $thread->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldNotReceive('startRunner');
    $fakes['commands']->shouldNotReceive('turn');

    $run = app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($run->turn_state)->toBe(TurnState::Pending)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit);
});

it('prepends queued steering messages to the prompt', function () {
    [, $task] = threadWithTask();
    PendingSteeringMessage::create(['root_task_id' => $task->id, 'text' => 'and the footer', 'source' => 'web', 'mode' => 'queue']);
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once()->with(Mockery::any(), Mockery::any(), "- and the footer\n\ngo");

    app(ThreadTurns::class)->start($task, 'go', null, null);
});
