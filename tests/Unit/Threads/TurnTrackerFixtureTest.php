<?php

use App\Enums\TurnMessageState;
use App\Services\ClaudeAccounts\AccountFailureKind;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/../../Helpers/RunnerStreamFixture.php';

function turnFixtureBase(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-01-01T00:00:00Z');
}

/**
 * The time of the last line of a type, optionally narrowed by a predicate.
 */
function turnFixtureLastLineAt(string $name, string $type, ?Closure $matches = null): CarbonImmutable
{
    $milliseconds = null;

    foreach (runnerStreamLines($name) as $line) {
        if (($line['type'] ?? null) === $type && ($matches === null || $matches($line))) {
            $milliseconds = $line['_ms'];
        }
    }

    return turnFixtureBase()->addMilliseconds((int) $milliseconds);
}

function turnFixtureLastResultAt(string $name): CarbonImmutable
{
    return turnFixtureLastLineAt($name, 'result');
}

function turnFixtureWasWaiting(array $replay): bool
{
    return collect($replay['changes'])->contains(fn ($change) => $change->waitingOnBackgroundTask);
}

it('ends natural-turn-end at the last result plus the grace window', function () {
    $replay = replayRunnerStream('natural-turn-end');

    expect($replay['endedAt'])->toEqual(turnFixtureLastResultAt('natural-turn-end')->addSeconds(30))
        ->and($replay['tracker']->state()['result_count'])->toBe(1)
        ->and($replay['tracker']->state()['cost_usd'])->toBeGreaterThan(0.0)
        ->and($replay['tracker']->state()['failure'])->toBeNull();
});

it('keeps background-bash-task open until the task list empties and the grace window passes', function () {
    $replay = replayRunnerStream('background-bash-task');
    $emptied = turnFixtureLastLineAt('background-bash-task', 'system', fn ($line) => ($line['subtype'] ?? null) === 'background_tasks_changed' && ($line['tasks'] ?? []) === []);
    $lastResult = turnFixtureLastResultAt('background-bash-task');

    expect($replay['endedAt'])->toEqual(max($emptied, $lastResult)->addSeconds(30))
        ->and($replay['tracker']->state()['result_count'])->toBe(1);
});

it('waits on the background task between the first result and its completion in background-bash-wake-result', function () {
    $replay = replayRunnerStream('background-bash-wake-result');

    expect(turnFixtureWasWaiting($replay))->toBeTrue()
        ->and($replay['tracker']->state()['result_count'])->toBe(3)
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt('background-bash-wake-result')->addSeconds(30));
});

it('ends background-subagent only after the last result and every task change settled', function () {
    $replay = replayRunnerStream('background-subagent');

    expect($replay['tracker']->state()['result_count'])->toBe(5)
        ->and($replay['tracker']->state()['background_task_count'])->toBe(0)
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt('background-subagent')->addSeconds(30));
});

it('completes both messages in a steered turn without ending between the results', function (string $name) {
    $replay = replayRunnerStream($name);
    $state = $replay['tracker']->state();
    $firstResult = collect(runnerStreamLines($name))->firstWhere('type', 'result')['_ms'];
    $secondResult = turnFixtureLastResultAt($name);

    expect($state['result_count'])->toBe(2)
        ->and(array_values(array_unique($state['uuids'])))->toBe([TurnMessageState::Completed->value])
        ->and($replay['endedAt'])->toEqual($secondResult->addSeconds(30))
        ->and($replay['endedAt'] > turnFixtureBase()->addMilliseconds($firstResult)->addSeconds(30))->toBeTrue();
})->with(['steer-in-grace-window', 'steer-during-final-text']);

it('ends interrupt-priority-now with the cancelled message terminal and no failure', function () {
    $replay = replayRunnerStream('interrupt-priority-now');
    $state = $replay['tracker']->state();

    expect($state['uuids'])->toContain(TurnMessageState::Cancelled->value)
        ->and(collect($state['uuids'])->every(fn ($value) => in_array($value, ['cancelled', 'completed'], true)))->toBeTrue()
        ->and($state['failure'])->toBeNull()
        ->and($replay['endedAt'])->not->toBeNull();
});

it('ends interrupt-result-without-result-key with no failure', function () {
    $replay = replayRunnerStream('interrupt-result-without-result-key');

    expect($replay['tracker']->state()['failure'])->toBeNull()
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt('interrupt-result-without-result-key')->addSeconds(30));
});

it('ends usage-limit at the result with the recorded reset time', function () {
    $replay = replayRunnerStream('usage-limit');
    $resetsAt = collect(runnerStreamLines('usage-limit'))->firstWhere('type', 'rate_limit_event')['rate_limit_info']['resetsAt'] ?? null;
    $failure = collect($replay['changes'])->map->failure->filter()->first();

    expect($failure->kind)->toBe(AccountFailureKind::UsageLimit)
        ->and($failure->resetsAt?->getTimestamp())->toBe((int) $resetsAt)
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt('usage-limit'));
});

it('ends authentication-failed at the result with an auth failure', function () {
    $replay = replayRunnerStream('authentication-failed');
    $failure = collect($replay['changes'])->map->failure->filter()->first();

    expect($failure->kind)->toBe(AccountFailureKind::AuthFailed)
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt('authentication-failed'));
});

it('never reports a failure for a transient 429', function () {
    $replay = replayRunnerStream('transient-429');

    expect(collect($replay['changes'])->map->failure->filter()->all())->toBe([])
        ->and($replay['tracker']->state()['failure'])->toBeNull()
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt('transient-429')->addSeconds(30));
});

it('ends normally for the synthetic cutoff message and for compaction', function (string $name, int $results) {
    $replay = replayRunnerStream($name);
    $state = $replay['tracker']->state();

    expect($state['result_count'])->toBe($results)
        ->and($state['failure'])->toBeNull()
        ->and($replay['endedAt'])->toEqual(turnFixtureLastResultAt($name)->addSeconds(30));
})->with([
    ['cutoff-synthetic-user-message', 2],
    ['compaction', 1],
]);

it('ends runner-exit-mid-turn on the broker line with no failure', function () {
    $replay = replayRunnerStream('runner-exit-mid-turn');

    expect($replay['tracker']->state()['failure'])->toBeNull()
        ->and($replay['tracker']->state()['runner_exited'])->toBeTrue()
        ->and($replay['endedAt'])->toEqual(turnFixtureLastLineAt('runner-exit-mid-turn', 'broker.runner_exited'));
});
