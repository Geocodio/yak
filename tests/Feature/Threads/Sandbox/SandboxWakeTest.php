<?php

use App\DataTransferObjects\SandboxWake;

test('each factory sets exactly one flag', function (string $factory, array $expected) {
    $wake = SandboxWake::$factory();

    expect([$wake->ready, $wake->waitingForSlot, $wake->restoring])->toBe($expected);
})->with([
    ['ready', [true, false, false]],
    ['waitingForSlot', [false, true, false]],
    ['restoring', [false, false, true]],
]);
