<?php

use App\Jobs\Middleware\PausesDuringDrain;
use Illuminate\Support\Facades\Cache;

test('clears the drain cache flag', function () {
    Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addMinutes(10));
    expect(Cache::has(PausesDuringDrain::CACHE_KEY))->toBeTrue();

    $this->artisan('yak:resume')->assertSuccessful();

    expect(Cache::has(PausesDuringDrain::CACHE_KEY))->toBeFalse();
});

test('is a no-op when flag is already absent', function () {
    Cache::forget(PausesDuringDrain::CACHE_KEY);

    $this->artisan('yak:resume')->assertSuccessful();

    expect(Cache::has(PausesDuringDrain::CACHE_KEY))->toBeFalse();
});
