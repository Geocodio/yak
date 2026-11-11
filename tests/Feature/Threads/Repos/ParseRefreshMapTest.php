<?php

use App\Services\Threads\RefreshTriggers;

test('a valid refresh map is returned', function () {
    expect(app(RefreshTriggers::class)->parseRefreshMap("refresh:\n  composer.lock: composer install\n", 1))
        ->toBe(['composer.lock' => 'composer install']);
});

test('invalid yaml gives an empty map', function () {
    expect(app(RefreshTriggers::class)->parseRefreshMap("refresh: [unclosed\n  : :", 1))->toBe([]);
});

test('non string values give an empty map', function () {
    expect(app(RefreshTriggers::class)->parseRefreshMap("refresh:\n  composer.lock:\n    - one\n", 1))->toBe([]);
});
