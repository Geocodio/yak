<?php

use App\Enums\TaskRunKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Listeners\DispatchAfterTurnWork;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

beforeEach(fn () => Queue::fake());

it('queues the run finisher for an initial and a clarification turn', function (TaskRunKind $kind) {
    [, $run] = endedTurn(kind: $kind);

    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run));

    Queue::assertPushed(FinishRunYakTurnJob::class, fn (FinishRunYakTurnJob $job) => $job->taskRunId === $run->id);
})->with([TaskRunKind::Initial, TaskRunKind::Clarification]);

it('queues one finisher for a repeated event', function () {
    [, $run] = endedTurn();

    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run));
    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run->fresh()));
    (new DispatchAfterTurnWork)->handle(new TurnFailed($run->fresh()));

    Queue::assertPushed(FinishRunYakTurnJob::class, 1);
});

it('queues the finisher through the event dispatcher', function () {
    [, $run] = endedTurn();

    event(new TurnCompleted($run));

    Queue::assertPushed(FinishRunYakTurnJob::class, 1);
});

it('queues nothing for a turn the guard stopped or a person interrupted', function (TurnState $state) {
    [, $run] = endedTurn(state: $state);

    (new DispatchAfterTurnWork)->handle(new TurnFailed($run));

    Queue::assertNothingPushed();
})->with([TurnState::StoppedByGuard, TurnState::Interrupted]);

it('queues nothing for kinds without a finisher', function (TaskRunKind $kind) {
    [, $run] = endedTurn(kind: $kind);

    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run));

    Queue::assertNothingPushed();
})->with([TaskRunKind::Review, TaskRunKind::Setup]);

it('keeps the claim after a successful dispatch', function () {
    [, $run] = endedTurn();

    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run));

    expect($run->fresh()->completion_dispatched_at)->not->toBeNull();
});

it('gives the claim back and rethrows when the dispatch fails', function () {
    $queue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $queue->shouldReceive('push', 'later')->andThrow(new RuntimeException('queue down'));
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->andReturn($queue);
    Queue::clearResolvedInstances();
    app()->instance(Factory::class, $factory);
    app()->instance('queue', $factory);
    [, $run] = endedTurn();

    expect(fn () => (new DispatchAfterTurnWork)->handle(new TurnCompleted($run)))->toThrow(RuntimeException::class)
        ->and($run->fresh()->completion_dispatched_at)->toBeNull();
});
