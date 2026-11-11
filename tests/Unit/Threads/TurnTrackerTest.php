<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\TurnMessageState;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\Threads\TurnTracker;
use Carbon\CarbonImmutable;

function turnTrackerNew(): TurnTracker
{
    return new TurnTracker(30);
}

function turnTrackerBase(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-01-01T00:00:00Z');
}

function turnTrackerAt(int $seconds): CarbonImmutable
{
    return turnTrackerBase()->addSeconds($seconds);
}

/** @return array<string, mixed> */
function turnTrackerResultLine(array $extra = []): array
{
    return ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 's1', ...$extra];
}

/** @return array<string, mixed> */
function turnTrackerLifecycle(string $uuid, string $state): array
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
    $tracker = turnTrackerNew();
    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(0));

    expect($tracker->tick(turnTrackerAt(29))->ended)->toBeFalse();
    expect($tracker->tick(turnTrackerAt(30))->ended)->toBeTrue();
    expect($tracker->tick(turnTrackerAt(60))->ended)->toBeFalse();
});

it('does not end before any result', function () {
    expect(turnTrackerNew()->tick(turnTrackerAt(999))->ended)->toBeFalse();
});

it('restarts the clock on a tool_result', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(0));
    $tracker->apply(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 'x']]]], turnTrackerAt(10));

    expect($tracker->tick(turnTrackerAt(30))->ended)->toBeFalse();
    expect($tracker->tick(turnTrackerAt(40))->ended)->toBeTrue();
});

it('is blocked by an open tool_use until its result arrives', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't1']]]], turnTrackerAt(0));
    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(1));
    $tracker->apply(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't2']]]], turnTrackerAt(2));

    expect($tracker->tick(turnTrackerAt(100))->ended)->toBeFalse();

    $tracker->apply(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't2']]]], turnTrackerAt(110));
    expect($tracker->tick(turnTrackerAt(140))->ended)->toBeTrue();
});

it('waits on background tasks and restarts the clock when they clear', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(0));
    $tracker->apply(['type' => 'system', 'subtype' => 'background_tasks_changed', 'tasks' => [['task_id' => 'a']]], turnTrackerAt(5));

    $change = $tracker->tick(turnTrackerAt(100));
    expect($change->ended)->toBeFalse()->and($change->waitingOnBackgroundTask)->toBeTrue();

    $tracker->apply(['type' => 'system', 'subtype' => 'background_tasks_changed', 'tasks' => []], turnTrackerAt(200));
    expect($tracker->tick(turnTrackerAt(229))->ended)->toBeFalse();
    expect($tracker->tick(turnTrackerAt(230))->ended)->toBeTrue();
});

it('is blocked by a tracked uuid that is still sent or queued', function () {
    $tracker = turnTrackerNew();
    $tracker->track('u1');
    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(0));

    expect($tracker->tick(turnTrackerAt(100))->ended)->toBeFalse();

    $tracker->apply(turnTrackerLifecycle('u1', 'queued'), turnTrackerAt(101));
    expect($tracker->tick(turnTrackerAt(200))->ended)->toBeFalse();

    $tracker->apply(turnTrackerLifecycle('u1', 'completed'), turnTrackerAt(201));
    expect($tracker->tick(turnTrackerAt(201))->ended)->toBeTrue();
});

it('absorbs a steer sent inside the window into the same turn', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(0));
    $tracker->track('steer');
    $tracker->apply(turnTrackerLifecycle('steer', 'queued'), turnTrackerAt(5));
    $tracker->apply(turnTrackerLifecycle('steer', 'started'), turnTrackerAt(6));
    $tracker->apply(turnTrackerLifecycle('steer', 'completed'), turnTrackerAt(20));
    $change = $tracker->apply(turnTrackerResultLine(), turnTrackerAt(21));

    expect($change->resultCount)->toBe(2);
    expect($tracker->tick(turnTrackerAt(50))->ended)->toBeFalse();
    $end = $tracker->tick(turnTrackerAt(51));
    expect($end->ended)->toBeTrue()->and($end->resultCount)->toBe(2);
});

it('treats cancelled as terminal and never moves a uuid backward', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(turnTrackerLifecycle('u', 'started'), turnTrackerAt(0));

    expect($tracker->apply(turnTrackerLifecycle('u', 'queued'), turnTrackerAt(1))->uuidStates)->toBe([]);
    expect($tracker->apply(turnTrackerLifecycle('u', 'cancelled'), turnTrackerAt(2))->uuidStates)->toBe(['u' => TurnMessageState::Cancelled]);
    expect($tracker->apply(turnTrackerLifecycle('u', 'started'), turnTrackerAt(3))->uuidStates)->toBe([]);

    $tracker->apply(turnTrackerResultLine(), turnTrackerAt(4));
    expect($tracker->tick(turnTrackerAt(34))->ended)->toBeTrue();
});

it('ends an interrupted turn without a failure', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(['type' => 'runner', 'event' => 'interrupted'], turnTrackerAt(0));
    $change = $tracker->apply(['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true], turnTrackerAt(1));

    expect($change->failure)->toBeNull()->and($change->resultCount)->toBe(1);
    $end = $tracker->tick(turnTrackerAt(31));
    expect($end->ended)->toBeTrue()->and($end->failure)->toBeNull();
});

it('ends on runner exit without a failure', function () {
    $change = turnTrackerNew()->apply(['type' => 'broker.runner_exited', 'exit_code' => 137], turnTrackerAt(0));

    expect($change->ended)->toBeTrue()->and($change->failure)->toBeNull();
});

it('ends immediately on a usage limit with resetsAt from the rejected rate limit event', function () {
    $tracker = turnTrackerNew();
    $tracker->apply(['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1893456000, 'rateLimitType' => 'five_hour']], turnTrackerAt(0));
    $change = $tracker->apply(turnTrackerResultLine(['is_error' => true, 'api_error_status' => 429, 'api_error' => 'usage_limit_reached']), turnTrackerAt(1));

    expect($change->ended)->toBeTrue()
        ->and($change->failure->kind)->toBe(AccountFailureKind::UsageLimit)
        ->and($change->failure->resetsAt->getTimestamp())->toBe(1893456000)
        ->and($change->failure->rateLimitType)->toBe('five_hour');
    expect($tracker->apply(turnTrackerResultLine(), turnTrackerAt(2))->ended)->toBeFalse();
});

it('ends immediately on an authentication failure', function () {
    $change = turnTrackerNew()->apply(turnTrackerResultLine(['is_error' => true, 'api_error_status' => 401]), turnTrackerAt(0));

    expect($change->ended)->toBeTrue()->and($change->failure->kind)->toBe(AccountFailureKind::AuthFailed);
});

it('does not fail the turn on a transient 429', function () {
    $tracker = turnTrackerNew();
    $change = $tracker->apply(turnTrackerResultLine(['is_error' => true, 'api_error_status' => 429]), turnTrackerAt(0));

    expect($change->ended)->toBeFalse()->and($change->failure)->toBeNull()->and($change->resultCount)->toBe(1);
    expect($tracker->tick(turnTrackerAt(30))->ended)->toBeTrue();
});

it('sums cost deltas across two results in one turn', function () {
    $tracker = turnTrackerNew();
    $first = $tracker->apply(turnTrackerResultLine(['total_cost_usd' => 0.5]), turnTrackerAt(0));
    $second = $tracker->apply(turnTrackerResultLine(['total_cost_usd' => 0.75]), turnTrackerAt(1));

    expect($first->costDeltaUsd)->toBe(0.5)->and($second->costDeltaUsd)->toBe(0.25);
});

it('continues identically after a state round trip', function () {
    $lines = [
        [turnTrackerLifecycle('u', 'queued'), 0],
        [['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't']]]], 1],
        [['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1893456000]], 2],
        [turnTrackerLifecycle('u', 'completed'), 3],
        [turnTrackerResultLine(['total_cost_usd' => 1.0]), 4],
    ];

    $whole = turnTrackerNew();
    $split = turnTrackerNew();
    foreach ($lines as $index => [$line, $seconds]) {
        $whole->apply($line, turnTrackerAt($seconds));
        $split->apply($line, turnTrackerAt($seconds));
        if ($index === 2) {
            $split = TurnTracker::fromState(json_decode(json_encode($split->state()), true));
        }
    }

    expect($split->state())->toEqual($whole->state());

    $final = turnTrackerResultLine(['is_error' => true, 'api_error_status' => 429, 'api_error' => 'usage_limit_reached', 'total_cost_usd' => 1.5]);
    $a = $whole->apply($final, turnTrackerAt(5));
    $b = TurnTracker::fromState(json_decode(json_encode($split->state()), true))->apply($final, turnTrackerAt(5));

    expect($b)->toEqual($a)->and($b->failure->resetsAt->getTimestamp())->toBe(1893456000);
});
