<?php

use App\Jobs\ClarificationReplyJob;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SetupYakJob;
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

it('keeps a job held for its whole retry window under the attempts column limit', function (string $jobClass) {
    $retryWindowSeconds = (int) now()->diffInSeconds((new ReflectionClass($jobClass))->newInstanceWithoutConstructor()->retryUntil());

    expect(intdiv($retryWindowSeconds, HoldsForClaudeAuth::RELEASE_DELAY_SECONDS))->toBeLessThan(255);
})->with([
    RunYakJob::class,
    RetryYakJob::class,
    ResearchYakJob::class,
    RunYakReviewJob::class,
    RunFollowUpJob::class,
    ClarificationReplyJob::class,
    SetupYakJob::class,
]);
