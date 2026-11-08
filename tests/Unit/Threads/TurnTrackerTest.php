<?php

use App\Enums\CommandState;
use App\Models\TaskRun;
use App\Services\Threads\TurnTracker;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Replays a recorded runner log. Lines the driver sent register their uuid
 * with the tracker; runner lines are observed. Stops after $stopAfterResults
 * results when given.
 *
 * @return array{0: TurnTracker, 1: list<array<string, mixed>>}
 */
function replayThreadFixture(string $name, ?int $stopAfterResults = null): array
{
    $tracker = new TurnTracker;
    $changes = [];
    $results = 0;

    foreach (file(base_path("tests/Fixtures/threads/{$name}.jsonl"), FILE_IGNORE_NEW_LINES) as $row) {
        $line = json_decode(explode("\t", $row, 2)[1], true);

        if (isset($line['_driver_sent'])) {
            if (isset($line['uuid'])) {
                $tracker->track($line['uuid']);
            }

            continue;
        }

        array_push($changes, ...$tracker->observe($line));

        if (($line['type'] ?? null) === 'result' && ++$results === $stopAfterResults) {
            break;
        }
    }

    return [$tracker, $changes];
}

function threadLifecycle(string $uuid, string $state): array
{
    return ['type' => 'command_lifecycle', 'command_uuid' => $uuid, 'state' => $state];
}

function threadResult(array $overrides = []): array
{
    return array_merge(['type' => 'result', 'subtype' => 'success', 'session_id' => 'session-1'], $overrides);
}

function threadBackgroundTasks(int $count): array
{
    return ['type' => 'system', 'subtype' => 'background_tasks_changed', 'tasks' => array_fill(0, $count, ['task_id' => 'a'])];
}

it('ends a plain turn on its result', function () {
    $tracker = new TurnTracker;
    $tracker->track('u1');

    expect($tracker->isEnded())->toBeFalse();

    $changes = [];
    foreach (['queued', 'started', 'completed'] as $state) {
        array_push($changes, ...$tracker->observe(threadLifecycle('u1', $state)));
    }

    expect($tracker->isEnded())->toBeFalse();

    $tracker->observe(threadResult());

    expect($tracker->isEnded())->toBeTrue()
        ->and(array_map(fn ($change) => $change->state, $changes))
        ->toBe([CommandState::Queued, CommandState::Started, CommandState::Completed]);
});

it('waits for a steer sent before the first result', function () {
    [$tracker] = replayThreadFixture('steer-during-final-text', 1);

    expect($tracker->isEnded())->toBeFalse()
        ->and($tracker->results())->toHaveCount(1);

    [$tracker] = replayThreadFixture('steer-during-final-text');

    expect($tracker->isEnded())->toBeTrue()
        ->and($tracker->results())->toHaveCount(2);
});

it('ends a recorded turn with a steer absorbed during tools', function () {
    [$tracker] = replayThreadFixture('steer-during-tools');

    expect($tracker->isEnded())->toBeTrue()
        ->and($tracker->attributes()['command_states'])->each->toBe('completed');
});

it('treats cancelled as terminal', function () {
    [$tracker, $changes] = replayThreadFixture('priority-now');

    expect($tracker->isEnded())->toBeTrue()
        ->and(collect($changes)->pluck('state'))->toContain(CommandState::Cancelled)
        ->and($tracker->attributes()['result_count'])->toBe(2);
});

it('does not end while a background task runs', function () {
    $tracker = new TurnTracker;
    $tracker->track('u1');
    $tracker->observe(threadBackgroundTasks(1));
    $tracker->observe(threadLifecycle('u1', 'completed'));
    $tracker->observe(threadResult());

    expect($tracker->isEnded())->toBeFalse()
        ->and($tracker->attributes()['background_task_count'])->toBe(1);
});

it('waits for the autonomous result after a background task finishes', function () {
    $tracker = new TurnTracker;
    $tracker->track('u1');
    $tracker->observe(threadBackgroundTasks(1));
    $tracker->observe(threadLifecycle('u1', 'completed'));
    $tracker->observe(threadResult());
    $tracker->observe(threadBackgroundTasks(0));

    expect($tracker->isEnded())->toBeFalse()
        ->and($tracker->attributes()['pending_background_results'])->toBe(1);

    $tracker->observe(threadResult());

    expect($tracker->isEnded())->toBeTrue();

    [$tracker] = replayThreadFixture('background-subagent');

    expect($tracker->isEnded())->toBeTrue()
        ->and($tracker->results())->toHaveCount(3);
});

it('accepts a result without a result key after an interrupt', function () {
    $tracker = new TurnTracker;
    $tracker->track('u1');
    $tracker->observe(threadLifecycle('u1', 'cancelled'));
    $tracker->observe(threadResult(['stop_reason' => 'tool_use']));

    expect($tracker->isEnded())->toBeTrue();
});

it('never moves a uuid backwards', function () {
    $tracker = new TurnTracker;
    $tracker->track('u1');
    $tracker->observe(threadLifecycle('u1', 'started'));

    expect($tracker->observe(threadLifecycle('u1', 'queued')))->toBe([]);

    $tracker->observe(threadLifecycle('u1', 'completed'));

    expect($tracker->observe(threadLifecycle('u1', 'cancelled')))->toBe([])
        ->and($tracker->observe(threadLifecycle('u1', 'started')))->toBe([])
        ->and($tracker->observe(threadLifecycle('unknown', 'started')))->toBe([])
        ->and($tracker->attributes()['command_states'])->toBe(['u1' => 'completed']);
});

it('sums model usage since the last result', function () {
    $tracker = new TurnTracker;
    $tracker->observe(threadResult([
        'total_cost_usd' => 0.5,
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        'modelUsage' => ['m' => ['inputTokens' => 10, 'outputTokens' => 4, 'cacheReadInputTokens' => 2, 'cacheCreationInputTokens' => 1, 'costUSD' => 0.9]],
        'model_usage_since_last_result' => ['a' => ['costUSD' => 0.25], 'b' => ['costUSD' => 0.125]],
    ]));
    $tracker->observe(threadResult([
        'total_cost_usd' => 0.75,
        'model_usage_since_last_result' => ['a' => ['costUSD' => 0.25]],
    ]));

    expect($tracker->costUsd())->toBe(0.625)
        ->and($tracker->tokens())->toBe(['input' => 10, 'output' => 4, 'cache_read' => 2, 'cache_creation' => 1]);
});

it('falls back to the session total difference', function () {
    $tracker = new TurnTracker;
    $tracker->observe(threadResult(['total_cost_usd' => 0.5]));
    $tracker->observe(threadResult(['total_cost_usd' => 0.75]));

    expect($tracker->costUsd())->toBe(0.75);

    $resumed = new TurnTracker(sessionTotalsUsd: ['session-1' => 1.0]);
    $resumed->observe(threadResult(['total_cost_usd' => 1.25]));

    expect($resumed->costUsd())->toBe(0.25);
});

it('round-trips through attributes', function () {
    [$original] = replayThreadFixture('background-subagent');
    $attributes = $original->attributes();

    $run = new TaskRun($attributes);
    $restored = TurnTracker::fromRun($run);

    expect($restored->isEnded())->toBe($original->isEnded())
        ->and($restored->attributes())->toBe($attributes)
        ->and($restored->costUsd())->toBe($original->costUsd());

    [$midTurn] = replayThreadFixture('steer-during-final-text', 1);
    $restoredMidTurn = TurnTracker::fromRun(new TaskRun($midTurn->attributes()));

    expect($restoredMidTurn->isEnded())->toBeFalse()
        ->and($restoredMidTurn->attributes())->toBe($midTurn->attributes());
});

it('waits for a subagent the model backgrounded on its own', function (int $run) {
    $tracker = new TurnTracker;
    $endedBeforeLastResult = false;
    $results = 0;

    foreach (file(base_path("tests/Fixtures/threads/own-background-subagent-{$run}.jsonl"), FILE_IGNORE_NEW_LINES) as $row) {
        $line = json_decode(explode("\t", $row, 2)[1], true);

        if (isset($line['_driver_sent'])) {
            if (isset($line['uuid'])) {
                $tracker->track($line['uuid']);
            }

            continue;
        }

        $tracker->observe($line);
        $results += ($line['type'] ?? null) === 'result' ? 1 : 0;
        $endedBeforeLastResult = $endedBeforeLastResult || ($tracker->isEnded() && $results < 5);
    }

    expect($endedBeforeLastResult)->toBeFalse()
        ->and($tracker->results())->toHaveCount(5)
        ->and($tracker->isEnded())->toBeTrue();
})->with([1, 2, 3, 4, 5]);

it('ends once with one answer after a sandbox pause lands in a tool call', function () {
    // The paused Bash call hit its wall-clock timeout on resume and moved to the background.
    [$tracker] = replayThreadFixture('pause-during-tool');

    expect($tracker->isEnded())->toBeTrue()
        ->and($tracker->results())->toHaveCount(1)
        ->and($tracker->results()[0]['result'])->toBe('FINISHED');
});
