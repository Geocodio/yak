<?php

use App\Enums\NotificationType;
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

test('database queue retry_after defaults above the longest job timeout', function () {
    expect(config('queue.connections.database.retry_after'))->toBe(4200);
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

test('per-task yak-claude jobs share SetupYakJob\'s 3600 second timeout', function () {
    // Laravel enforces $timeout via pcntl_alarm → posix_kill(getmypid(), SIGKILL).
    // Agent sessions with browser capture regularly exceed 10 minutes; a lower
    // cap silently murders the worker mid-stream (tasks 4406/4407/4408 hit this).
    $jobs = [
        new RunYakJob(YakTask::factory()->pending()->make()),
        new RetryYakJob(YakTask::factory()->retrying()->make()),
        new ResearchYakJob(YakTask::factory()->pending()->make()),
        new ClarificationReplyJob(YakTask::factory()->awaitingClarification()->make(), 'test reply'),
        new SetupYakJob(YakTask::factory()->pending()->make()),
    ];

    foreach ($jobs as $job) {
        expect($job->timeout)->toBe(3600);
    }
});

test('per-task yak-claude jobs have exponential backoff', function () {
    $jobs = [
        new RunYakJob(YakTask::factory()->pending()->make()),
        new RetryYakJob(YakTask::factory()->retrying()->make()),
        new ResearchYakJob(YakTask::factory()->pending()->make()),
        new ClarificationReplyJob(YakTask::factory()->awaitingClarification()->make(), 'test reply'),
    ];

    foreach ($jobs as $job) {
        expect($job->backoff)->toBe([1, 5, 10]);
    }
});

test('setup job runs only once and does not retry', function () {
    $job = new SetupYakJob(YakTask::factory()->pending()->make());

    expect($job->tries)->toBe(1);
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
        new CreatePullRequestJob(YakTask::factory()->awaitingCi()->make()),
        new SendNotificationJob(YakTask::factory()->pending()->make(), NotificationType::Progress, 'test'),
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
        new CreatePullRequestJob(YakTask::factory()->awaitingCi()->make()),
        new SendNotificationJob(YakTask::factory()->pending()->make(), NotificationType::Progress, 'test'),
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
        new CreatePullRequestJob(YakTask::factory()->awaitingCi()->make()),
        new SendNotificationJob(YakTask::factory()->pending()->make(), NotificationType::Progress, 'test'),
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

test('retry_after exceeds setup job timeout', function () {
    $retryAfter = config('queue.connections.database.retry_after');
    $setupTimeout = (new SetupYakJob(YakTask::factory()->pending()->make()))->timeout;

    expect($retryAfter)->toBeGreaterThan($setupTimeout);
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

/*
|--------------------------------------------------------------------------
| Production Supervisord Configuration
|--------------------------------------------------------------------------
|
| docker/supervisord.conf is the file that actually ships — Dockerfile
| copies it to /etc/supervisor/conf.d/yak.conf and runs it as the CMD.
| (base_path('supervisord.conf') above is a local-dev leftover.)
|
*/

/**
 * Parse docker/supervisord.conf into [program name => [directive => value]].
 *
 * @return array<string, array<string, string>>
 */
function productionSupervisordPrograms(): array
{
    $path = base_path('docker/supervisord.conf');
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    $programs = [];
    $current = null;

    foreach ($lines as $line) {
        $line = trim($line);

        if (str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }

        if (preg_match('/^\[program:(.+)\]$/', $line, $matches) === 1) {
            $current = $matches[1];
            $programs[$current] = [];

            continue;
        }

        if (str_starts_with($line, '[')) {
            $current = null;

            continue;
        }

        if ($current !== null && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $programs[$current][trim($key)] = trim($value);
        }
    }

    return $programs;
}

test('every supervisord program that runs artisan runs as www-data', function () {
    // supervisord itself runs as root, so a program with no `user=` inherits
    // root. Any program that drives the Laravel app must drop to www-data:
    // /data, /app/storage and the shared CLAUDE_CONFIG_DIR are all www-data
    // owned, and root-owned files landing there lock www-data out.
    $programs = productionSupervisordPrograms();

    $artisanPrograms = array_filter(
        $programs,
        fn (array $directives): bool => str_contains($directives['command'] ?? '', 'artisan'),
    );

    expect($artisanPrograms)->not->toBeEmpty();

    foreach ($artisanPrograms as $name => $directives) {
        expect($directives['user'] ?? null)->toBe('www-data', "[program:{$name}] must declare user=www-data");
    }
});

test('scheduler runs as www-data so healthchecks cannot leave root-owned claude state', function () {
    // yak:healthcheck runs every 15 minutes and shells the Claude CLI out
    // against /home/yak/.claude (ClaudeAuthCheck). As root it leaves a
    // root-owned .oauth_refresh.lock behind, which blocks every subsequent
    // www-data OAuth refresh until someone re-authenticates by hand.
    $programs = productionSupervisordPrograms();

    expect($programs)->toHaveKey('scheduler');
    expect($programs['scheduler']['user'] ?? null)->toBe('www-data');
});
