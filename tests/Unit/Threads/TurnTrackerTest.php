<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\TurnMessageState;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\Threads\TurnTracker;
use Carbon\CarbonImmutable;

function tracker(): TurnTracker
{
    return new TurnTracker(30);
}

function base(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-01-01T00:00:00Z');
}

function at(int $seconds): CarbonImmutable
{
    return base()->addSeconds($seconds);
}

/** @return array<string, mixed> */
function resultLine(array $extra = []): array
{
    return ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 's1', ...$extra];
}

/** @return array<string, mixed> */
function lifecycle(string $uuid, string $state): array
{
    return ['type' => 'command_lifecycle', 'command_uuid' => $uuid, 'state' => $state];
}

it('has an empty none change', function () {
    $change = TurnTrackerChange::none();

    expect($change->ended)->toBeFalse()
        ->and($change->uuidStates)->toBe([])
        ->and($change->resultCount)->toBe(0)
        ->and($change->failure)->toBeNull()
        ->and($change->costDeltaUsd)->toBe(0.0)
        ->and($change->waitingOnBackgroundTask)->toBeFalse();
});

it('ends no earlier than the grace window after the last result', function () {
    $tracker = tracker();
    $tracker->apply(resultLine(), at(0));

    expect($tracker->tick(at(29))->ended)->toBeFalse();
    expect($tracker->tick(at(30))->ended)->toBeTrue();
    expect($tracker->tick(at(60))->ended)->toBeFalse();
});

it('does not end before any result', function () {
    expect(tracker()->tick(at(999))->ended)->toBeFalse();
});

it('restarts the clock on a tool_result', function () {
    $tracker = tracker();
    $tracker->apply(resultLine(), at(0));
    $tracker->apply(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'x']]]], at(10));

    expect($tracker->tick(at(30))->ended)->toBeFalse();
    expect($tracker->tick(at(40))->ended)->toBeTrue();
});

it('is blocked by an open tool_use until its result arrives', function () {
    $tracker = tracker();
    $tracker->apply(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't1']]]], at(0));
    $tracker->apply(resultLine(), at(1));
    $tracker->apply(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't2']]]], at(2));

    expect($tracker->tick(at(100))->ended)->toBeFalse();

    $tracker->apply(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't2']]]], at(110));
    expect($tracker->tick(at(140))->ended)->toBeTrue();
});

it('waits on background tasks and restarts the clock when they clear', function () {
    $tracker = tracker();
    $tracker->apply(resultLine(), at(0));
    $tracker->apply(['type' => 'system', 'subtype' => 'background_tasks_changed', 'tasks' => [['task_id' => 'a']]], at(5));

    $change = $tracker->tick(at(100));
    expect($change->ended)->toBeFalse()->and($change->waitingOnBackgroundTask)->toBeTrue();

    $tracker->apply(['type' => 'system', 'subtype' => 'background_tasks_changed', 'tasks' => []], at(200));
    expect($tracker->tick(at(229))->ended)->toBeFalse();
    expect($tracker->tick(at(230))->ended)->toBeTrue();
});

it('is blocked by a tracked uuid that is still sent or queued', function () {
    $tracker = tracker();
    $tracker->track('u1');
    $tracker->apply(resultLine(), at(0));

    expect($tracker->tick(at(100))->ended)->toBeFalse();

    $tracker->apply(lifecycle('u1', 'queued'), at(101));
    expect($tracker->tick(at(200))->ended)->toBeFalse();

    $tracker->apply(lifecycle('u1', 'completed'), at(201));
    expect($tracker->tick(at(201))->ended)->toBeTrue();
});

it('absorbs a steer sent inside the window into the same turn', function () {
    $tracker = tracker();
    $tracker->apply(resultLine(), at(0));
    $tracker->track('steer');
    $tracker->apply(lifecycle('steer', 'queued'), at(5));
    $tracker->apply(lifecycle('steer', 'started'), at(6));
    $tracker->apply(lifecycle('steer', 'completed'), at(20));
    $change = $tracker->apply(resultLine(), at(21));

    expect($change->resultCount)->toBe(2);
    expect($tracker->tick(at(50))->ended)->toBeFalse();
    $end = $tracker->tick(at(51));
    expect($end->ended)->toBeTrue()->and($end->resultCount)->toBe(2);
});

it('treats cancelled as terminal and never moves a uuid backward', function () {
    $tracker = tracker();
    $tracker->apply(lifecycle('u', 'started'), at(0));

    expect($tracker->apply(lifecycle('u', 'queued'), at(1))->uuidStates)->toBe([]);
    expect($tracker->apply(lifecycle('u', 'cancelled'), at(2))->uuidStates)->toBe(['u' => TurnMessageState::Cancelled]);
    expect($tracker->apply(lifecycle('u', 'started'), at(3))->uuidStates)->toBe([]);

    $tracker->apply(resultLine(), at(4));
    expect($tracker->tick(at(34))->ended)->toBeTrue();
});

it('ends an interrupted turn without a failure', function () {
    $tracker = tracker();
    $tracker->apply(['type' => 'runner', 'event' => 'interrupted'], at(0));
    $change = $tracker->apply(['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true], at(1));

    expect($change->failure)->toBeNull()->and($change->resultCount)->toBe(1);
    $end = $tracker->tick(at(31));
    expect($end->ended)->toBeTrue()->and($end->failure)->toBeNull();
});

it('ends on runner exit without a failure', function () {
    $change = tracker()->apply(['type' => 'broker.runner_exited', 'exit_code' => 137], at(0));

    expect($change->ended)->toBeTrue()->and($change->failure)->toBeNull();
});

it('ends immediately on a usage limit with resetsAt from the rejected rate limit event', function () {
    $tracker = tracker();
    $tracker->apply(['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1893456000, 'rateLimitType' => 'five_hour']], at(0));
    $change = $tracker->apply(resultLine(['is_error' => true, 'api_error_status' => 429, 'api_error' => 'usage_limit_reached']), at(1));

    expect($change->ended)->toBeTrue()
        ->and($change->failure->kind)->toBe(AccountFailureKind::UsageLimit)
        ->and($change->failure->resetsAt->getTimestamp())->toBe(1893456000)
        ->and($change->failure->rateLimitType)->toBe('five_hour');
    expect($tracker->apply(resultLine(), at(2))->ended)->toBeFalse();
});

it('ends immediately on an authentication failure', function () {
    $change = tracker()->apply(resultLine(['is_error' => true, 'api_error_status' => 401]), at(0));

    expect($change->ended)->toBeTrue()->and($change->failure->kind)->toBe(AccountFailureKind::AuthFailed);
});

it('does not fail the turn on a transient 429', function () {
    $tracker = tracker();
    $change = $tracker->apply(resultLine(['is_error' => true, 'api_error_status' => 429]), at(0));

    expect($change->ended)->toBeFalse()->and($change->failure)->toBeNull()->and($change->resultCount)->toBe(1);
    expect($tracker->tick(at(30))->ended)->toBeTrue();
});

it('sums cost deltas across two results in one turn', function () {
    $tracker = tracker();
    $first = $tracker->apply(resultLine(['total_cost_usd' => 0.5]), at(0));
    $second = $tracker->apply(resultLine(['total_cost_usd' => 0.75]), at(1));

    expect($first->costDeltaUsd)->toBe(0.5)->and($second->costDeltaUsd)->toBe(0.25);
});

it('continues identically after a state round trip', function () {
    $lines = [
        [lifecycle('u', 'queued'), 0],
        [['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't']]]], 1],
        [['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1893456000]], 2],
        [lifecycle('u', 'completed'), 3],
        [resultLine(['total_cost_usd' => 1.0]), 4],
    ];

    $whole = tracker();
    $split = tracker();
    foreach ($lines as $index => [$line, $seconds]) {
        $whole->apply($line, at($seconds));
        $split->apply($line, at($seconds));
        if ($index === 2) {
            $split = TurnTracker::fromState(json_decode(json_encode($split->state()), true));
        }
    }

    expect($split->state())->toEqual($whole->state());

    $final = resultLine(['is_error' => true, 'api_error_status' => 429, 'api_error' => 'usage_limit_reached', 'total_cost_usd' => 1.5]);
    $a = $whole->apply($final, at(5));
    $b = TurnTracker::fromState(json_decode(json_encode($split->state()), true))->apply($final, at(5));

    expect($b)->toEqual($a)->and($b->failure->resetsAt->getTimestamp())->toBe(1893456000);
});
