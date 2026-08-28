<?php

use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Services\HealthCheck\ClaudeAuthCheck;
use Illuminate\Support\Facades\Cache;

it('releases the job when Claude auth is unusable', function () {
    Cache::put(ClaudeAuthCheck::UNUSABLE_CACHE_KEY, true, 3600);

    $job = new class
    {
        public ?int $released = null;

        public function release(int $delay): void
        {
            $this->released = $delay;
        }
    };

    $ran = false;
    (new HoldsForClaudeAuth)->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse();
    expect($job->released)->toBe(HoldsForClaudeAuth::RELEASE_DELAY_SECONDS);
});

it('passes the job through when Claude auth is healthy', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);

    $ran = false;
    (new HoldsForClaudeAuth)->handle(new stdClass, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});
