<?php

use App\Support\Duration;

it('formats tool call durations at a useful resolution', function (?int $ms, string $expected) {
    expect(Duration::forHumans($ms))->toBe($expected);
})->with([
    [null, '—'],
    [-1, '—'],
    [0, '0ms'],
    [420, '420ms'],
    [1000, '1s'],
    [1500, '1.5s'],
    [9900, '9.9s'],
    [12400, '12s'],
    [59_000, '59s'],
    [65_000, '1m 5s'],
    [120_000, '2m'],
    [3_600_000, '1h'],
    [5_400_000, '1h 30m'],
]);
