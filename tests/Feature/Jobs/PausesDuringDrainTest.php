<?php

use App\Jobs\Middleware\PausesDuringDrain;
use Illuminate\Support\Facades\Cache;

function makePausesDuringDrainJobDouble(): object
{
    return new class
    {
        public int $releaseCalls = 0;

        public ?int $releaseDelay = null;

        public function release(int $delay = 0): void
        {
            $this->releaseCalls++;
            $this->releaseDelay = $delay;
        }
    };
}

test('passes through when drain flag is not set', function () {
    Cache::forget(PausesDuringDrain::CACHE_KEY);

    $called = false;
    $job = makePausesDuringDrainJobDouble();

    (new PausesDuringDrain)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue()
        ->and($job->releaseCalls)->toBe(0);
});

test('releases job with delay when drain flag is set', function () {
    Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addMinutes(10));

    $called = false;
    $job = makePausesDuringDrainJobDouble();

    (new PausesDuringDrain)->handle($job, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeFalse()
        ->and($job->releaseCalls)->toBe(1)
        ->and($job->releaseDelay)->toBe(PausesDuringDrain::RELEASE_DELAY_SECONDS);
});

test('passes through when drain flag is set but job cannot be released', function () {
    Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addMinutes(10));

    $called = false;
    $jobWithoutRelease = new class
    {
        // intentionally has no release() method
    };

    (new PausesDuringDrain)->handle($jobWithoutRelease, function () use (&$called) {
        $called = true;
    });

    expect($called)->toBeTrue();
});
