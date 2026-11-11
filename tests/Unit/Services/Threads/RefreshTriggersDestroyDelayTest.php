<?php

use App\Services\Threads\RefreshTriggers;
use Tests\TestCase;

uses(TestCase::class);

test('parseDestroyAfterHours reads a positive integer only', function (string $yaml, ?int $expected) {
    expect(app(RefreshTriggers::class)->parseDestroyAfterHours($yaml))->toBe($expected);
})->with([
    'valid' => ['destroy_after_hours: 168', 168],
    'zero' => ['destroy_after_hours: 0', null],
    'negative' => ['destroy_after_hours: -5', null],
    'string' => ['destroy_after_hours: "soon"', null],
    'numeric string' => ['destroy_after_hours: "168"', null],
    'float' => ['destroy_after_hours: 1.5', null],
    'missing' => ['refresh: {}', null],
    'broken yaml' => ["destroy_after_hours: [\n", null],
    'not a map' => ['just a string', null],
]);
