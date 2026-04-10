<?php

use App\Jobs\ClarificationReplyJob;
use App\Jobs\CleanupJob;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\ProcessWebhookJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\YakTask;

/*
|--------------------------------------------------------------------------
| Queue Configuration
|--------------------------------------------------------------------------
*/

test('database queue retry_after defaults to 660 seconds', function () {
    expect(config('queue.connections.database.retry_after'))->toBe(660);
});

/*
|--------------------------------------------------------------------------
| yak-claude Queue Jobs
|--------------------------------------------------------------------------
*/

test('yak-claude jobs dispatch to yak-claude queue', function () {
    $jobs = [
        new RunYakJob(YakTask::factory()->pending()->make()),
        new RetryYakJob(YakTask::factory()->retrying()->make()),
        new ResearchYakJob(YakTask::factory()->pending()->make()),
        new SetupYakJob(YakTask::factory()->pending()->make()),
        new ClarificationReplyJob(YakTask::factory()->awaitingClarification()->make(), 'test reply'),
    ];

    foreach ($jobs as $job) {
        expect($job->queue)->toBe('yak-claude');
    }
});

test('yak-claude jobs have 600 second timeout', function () {
    $jobs = [
        new RunYakJob(YakTask::factory()->pending()->make()),
        new RetryYakJob(YakTask::factory()->retrying()->make()),
        new ResearchYakJob(YakTask::factory()->pending()->make()),
        new SetupYakJob(YakTask::factory()->pending()->make()),
        new ClarificationReplyJob(YakTask::factory()->awaitingClarification()->make(), 'test reply'),
    ];

    foreach ($jobs as $job) {
        expect($job->timeout)->toBe(600);
    }
});

test('yak-claude jobs have exponential backoff', function () {
    $jobs = [
        new RunYakJob(YakTask::factory()->pending()->make()),
        new RetryYakJob(YakTask::factory()->retrying()->make()),
        new ResearchYakJob(YakTask::factory()->pending()->make()),
        new SetupYakJob(YakTask::factory()->pending()->make()),
        new ClarificationReplyJob(YakTask::factory()->awaitingClarification()->make(), 'test reply'),
    ];

    foreach ($jobs as $job) {
        expect($job->backoff)->toBe([1, 5, 10]);
    }
});

/*
|--------------------------------------------------------------------------
| Default Queue Jobs
|--------------------------------------------------------------------------
*/

test('default queue jobs dispatch to default queue', function () {
    $jobs = [
        new ProcessCIResultJob(YakTask::factory()->awaitingCi()->make(), true),
        new ProcessWebhookJob,
        new CreatePullRequestJob,
        new SendNotificationJob,
        new CleanupJob,
    ];

    foreach ($jobs as $job) {
        expect($job->queue)->toBe('default');
    }
});

test('default queue jobs have 30 second timeout', function () {
    $jobs = [
        new ProcessCIResultJob(YakTask::factory()->awaitingCi()->make(), true),
        new ProcessWebhookJob,
        new CreatePullRequestJob,
        new SendNotificationJob,
        new CleanupJob,
    ];

    foreach ($jobs as $job) {
        expect($job->timeout)->toBe(30);
    }
});

test('default queue jobs have exponential backoff', function () {
    $jobs = [
        new ProcessCIResultJob(YakTask::factory()->awaitingCi()->make(), true),
        new ProcessWebhookJob,
        new CreatePullRequestJob,
        new SendNotificationJob,
        new CleanupJob,
    ];

    foreach ($jobs as $job) {
        expect($job->backoff)->toBe([1, 5, 10]);
    }
});

/*
|--------------------------------------------------------------------------
| retry_after Exceeds Timeouts
|--------------------------------------------------------------------------
*/

test('retry_after exceeds yak-claude job timeout', function () {
    $retryAfter = config('queue.connections.database.retry_after');

    expect($retryAfter)->toBeGreaterThan(600);
});

test('retry_after exceeds default job timeout', function () {
    $retryAfter = config('queue.connections.database.retry_after');

    expect($retryAfter)->toBeGreaterThan(30);
});

/*
|--------------------------------------------------------------------------
| Supervisord Configuration
|--------------------------------------------------------------------------
*/

test('supervisord config exists', function () {
    expect(file_exists(base_path('supervisord.conf')))->toBeTrue();
});

test('supervisord config has yak-claude worker with correct settings', function () {
    $config = file_get_contents(base_path('supervisord.conf'));

    expect($config)
        ->toContain('[program:yak-claude-worker]')
        ->toContain('--queue=yak-claude')
        ->toContain('--timeout=600')
        ->toContain('numprocs=1');
});

test('supervisord config has default worker with correct settings', function () {
    $config = file_get_contents(base_path('supervisord.conf'));

    expect($config)
        ->toContain('[program:default-worker]')
        ->toContain('--queue=default')
        ->toContain('--timeout=30')
        ->toContain('numprocs=3');
});
