<?php

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
