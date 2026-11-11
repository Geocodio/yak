<?php

$fixtures = glob(__DIR__ . '/../Fixtures/runner-streams/*.jsonl') ?: [];

it('has the required fixture set', function () use ($fixtures) {
    expect($fixtures)->toHaveCount(14);
});

it('contains only valid scrubbed JSON lines', function (string $path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    $header = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
    expect($header['scrubbed'])->toBeTrue()
        ->and($header)->toHaveKeys(['fixture', 'source'])
        ->and($header['fixture'])->toBe(basename($path, '.jsonl'));

    foreach ($lines as $number => $line) {
        expect(json_decode($line, true))->toBeArray("line {$number} of " . basename($path));
        expect($line)->not->toMatch(
            '/sk-ant-|yakph-|@geocod\.io|\/Users\/|\/home\/(?!yak\b)\w|ghp_|ghs_|Mathias/',
            'line ' . $number . ' of ' . basename($path),
        );
    }
})->with(array_combine(array_map('basename', $fixtures), array_map(fn ($path) => [$path], $fixtures)));
