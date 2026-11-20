<?php

use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/ThreadTurnHelpers.php';

const AGENT_FIXTURE_PROMPT_UUID = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28';

/**
 * A running turn whose prompt uuid is the one the agent fixtures use.
 *
 * @return array{0: Thread, 1: TaskRun}
 */
function agentFixtureTurn(): array
{
    config(['yak.threads.subagents' => true]);
    [$thread, $run] = runningTurnFor(['prompt_uuid' => AGENT_FIXTURE_PROMPT_UUID, 'prompt' => 'scrubbed text 1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => AGENT_FIXTURE_PROMPT_UUID]);
    ThreadTurns::trackUuid($run, AGENT_FIXTURE_PROMPT_UUID);

    return [$thread, $run];
}

/**
 * The lines of a fixture with the sequence each is delivered under, so a test can play a stretch,
 * let time pass and play the rest.
 *
 * @return list<array<string, mixed>>
 */
function agentFixtureLines(string $name): array
{
    $lines = [];

    foreach (file(base_path("tests/Fixtures/runner-streams/{$name}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode($raw, true);

        if (is_array($line) && ! isset($line['fixture']) && ! isset($line['_driver_sent'])) {
            $lines[] = $line;
        }
    }

    return $lines;
}

/**
 * Plays fixture lines `[$from, $to)` into the driver as generation 1 entries.
 */
function playAgentFixture(Thread $thread, string $name, int $from = 0, ?int $to = null): void
{
    $lines = agentFixtureLines($name);
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);

    foreach (array_slice($lines, $from, $to === null ? null : $to - $from, true) as $index => $line) {
        $driver->handle($streams->pushOut('threads:' . $thread->id, 1, $index + 1, $line));
    }
}

/**
 * The position just after the first line that matches, to split a fixture there.
 *
 * @param  Closure(array<string, mixed>): bool  $matches
 */
function agentFixtureIndexAfter(string $name, Closure $matches, int $skip = 0): int
{
    foreach (agentFixtureLines($name) as $index => $line) {
        if ($matches($line) && $skip-- === 0) {
            return $index + 1;
        }
    }

    throw new RuntimeException("No line of {$name} matches.");
}
