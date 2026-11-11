<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Services\Threads\TurnTracker;
use Carbon\CarbonImmutable;

/**
 * Reads a recorded runner stream fixture, skipping the header line. A line
 * without `_ms` inherits the previous line's value.
 *
 * @return list<array<string, mixed>>
 */
function runnerStreamLines(string $name): array
{
    $rows = file(__DIR__ . "/../fixtures/runner-streams/{$name}.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($rows);

    $previousMs = 0;
    $lines = [];

    foreach ($rows as $row) {
        $line = json_decode($row, true, flags: JSON_THROW_ON_ERROR);
        $line['_ms'] ??= $previousMs;
        $previousMs = $line['_ms'];
        $lines[] = $line;
    }

    return $lines;
}

/**
 * Replays a fixture through a TurnTracker with the clock driven by `_ms`. A
 * real driver ticks whenever a grace window could close, so the replay ticks at
 * every line time plus the grace window that falls before the next line, then
 * once more at the last line time plus the grace window plus one second.
 * `endedAt` is therefore the exact moment the tracker ended.
 *
 * @param  list<string>  $trackUuids
 * @return array{tracker: TurnTracker, changes: list<TurnTrackerChange>, endedAt: ?CarbonImmutable}
 */
function replayRunnerStream(string $name, int $graceSeconds = 30, array $trackUuids = [], ?CarbonImmutable $base = null): array
{
    $base ??= CarbonImmutable::parse('2026-01-01T00:00:00Z');
    $tracker = new TurnTracker($graceSeconds);
    $changes = [];
    $endedAt = null;
    $lines = runnerStreamLines($name);
    $times = array_map(fn (array $line) => $base->addMilliseconds((int) $line['_ms']), $lines);

    foreach ($trackUuids as $uuid) {
        $tracker->track($uuid);
    }

    $tick = function (CarbonImmutable $at) use ($tracker, &$changes, &$endedAt): void {
        $change = $tracker->tick($at);
        $changes[] = $change;
        $endedAt ??= $change->ended ? $at : null;
    };

    $boundaries = array_map(fn (CarbonImmutable $time) => $time->addSeconds($graceSeconds), $times);
    sort($boundaries);

    foreach ($lines as $index => $line) {
        while ($endedAt === null && $boundaries !== [] && $boundaries[0] < $times[$index]) {
            $tick(array_shift($boundaries));
        }

        if ($endedAt !== null) {
            break;
        }

        $change = $tracker->apply($line, $times[$index]);
        $changes[] = $change;
        $endedAt = $change->ended ? $times[$index] : null;
    }

    if ($endedAt === null) {
        $lastAt = $times[array_key_last($times)];

        foreach ([...$boundaries, $lastAt->addSeconds($graceSeconds + 1)] as $boundary) {
            if ($endedAt === null) {
                $tick($boundary);
            }
        }
    }

    return ['tracker' => $tracker, 'changes' => $changes, 'endedAt' => $endedAt];
}
