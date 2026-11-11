<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Models\TaskRun;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

it('completes a turn once', function () {
    Event::fake([TurnCompleted::class]);
    [$thread, $run] = runningTurnFor();

    replayRunnerFixture($thread, 'natural-turn-end');

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->completion_event_emitted_at)->not->toBeNull()
        ->and($thread->refresh()->runner_state)->toBe(RunnerState::Idle);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
});

it('re-dispatches after a crash between transition and dispatch, once', function () {
    Event::fake([TurnCompleted::class]);
    [$thread, $run] = runningTurnFor(['turn_state' => TurnState::Completed, 'completion_event_emitted_at' => null]);

    app(ThreadDriver::class)->tick();
    app(ThreadDriver::class)->tick();

    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    expect($run->refresh()->completion_event_emitted_at)->not->toBeNull();
});

it('releases the account slot and the thread lock', function () {
    $concurrency = Mockery::mock(AccountConcurrency::class);
    $concurrency->shouldReceive('release')->once();
    app()->instance(AccountConcurrency::class, $concurrency);
    [$thread] = runningTurnFor();

    replayRunnerFixture($thread, 'natural-turn-end');

    expect($thread->refresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($thread->lock_turn_id)->toBeNull();
});

it('does not finish a turn while a background task is pending', function () {
    Event::fake([TurnCompleted::class]);
    $concurrency = Mockery::mock(AccountConcurrency::class);
    $concurrency->shouldNotReceive('release');
    app()->instance(AccountConcurrency::class, $concurrency);
    $waiting = new TurnTrackerChange(ended: true, waitingOnBackgroundTask: true);
    bindTrackerChanges([$waiting, new TurnTrackerChange(ended: true)], fn (): TurnTrackerChange => $waiting);
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'result']);
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($thread->refresh()->runner_state)->toBe(RunnerState::Busy)
        ->and($thread->lock_holder)->toBe(ThreadLockHolder::Sandbox);
    Event::assertNotDispatched(TurnCompleted::class);

    $concurrency->shouldReceive('release')->once();
    pushRunnerLine($thread, 2, ['type' => 'result']);

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    expect(TaskRun::query()->whereNull('completion_event_emitted_at')->where('turn_state', TurnState::Completed)->exists())->toBeFalse();
});
