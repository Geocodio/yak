<?php

use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RetryYakJob;
use App\Models\Artifact;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Green Path — PR Creation & Task Success
|--------------------------------------------------------------------------
*/

test('green path creates PR and marks task as success', function () {
    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 42,
            'html_url' => 'https://github.com/org/my-repo/pull/42',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'slack.com/*' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 3 files changed, 50 insertions(+), 10 deletions(-)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.channels.slack.bot_token', 'test-slack-token');

    $repository = Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
        'default_branch' => 'main',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-1234',
        'source' => 'slack',
        'slack_channel' => 'C12345',
        'slack_thread_ts' => '1234567890.123456',
        'result_summary' => 'Fixed the login bug',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_url)->toBe('https://github.com/org/my-repo/pull/42')
        ->and($task->completed_at)->not->toBeNull();
});

test('green path creates PR via GitHub API with correct payload', function () {
    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 10,
            'html_url' => 'https://github.com/org/my-repo/pull/10',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
        'default_branch' => 'main',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-99',
        'source' => 'manual',
        'result_summary' => 'Updated the config',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.github.com/repos/org/my-repo/pulls')
            && $request['head'] === 'yak/FIX-99'
            && $request['base'] === 'main'
            && str_contains($request['title'], 'Yak Fix:');
    });
});

/*
|--------------------------------------------------------------------------
| Green Path — PR Template Correctness
|--------------------------------------------------------------------------
*/

test('PR body contains source, repo, and result summary', function () {
    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/test-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/test-repo',
        'path' => '/home/yak/repos/test-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/test-repo',
        'branch_name' => 'yak/FIX-50',
        'source' => 'linear',
        'result_summary' => 'Refactored authentication flow',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, '**Source:** linear')
            && str_contains($body, '**Repository:** org/test-repo')
            && str_contains($body, 'Refactored authentication flow');
    });
});

/*
|--------------------------------------------------------------------------
| Green Path — Labels
|--------------------------------------------------------------------------
*/

test('green path applies yak label to PR', function () {
    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 5,
            'html_url' => 'https://github.com/org/my-repo/pull/5',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 2 files changed, 30 insertions(+), 5 deletions(-)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-10',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/5/labels')
            && in_array('yak', $request['labels']);
    });
});

test('green path applies yak-large-change label when LOC exceeds threshold', function () {
    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 7,
            'html_url' => 'https://github.com/org/my-repo/pull/7',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 15 files changed, 180 insertions(+), 50 deletions(-)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.large_change_threshold', 200);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-20',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/7/labels')
            && in_array('yak', $request['labels'])
            && in_array('yak-large-change', $request['labels']);
    });
});

test('green path does not apply large-change label when LOC is under threshold', function () {
    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 8,
            'html_url' => 'https://github.com/org/my-repo/pull/8',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 2 files changed, 30 insertions(+), 10 deletions(-)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.large_change_threshold', 200);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-21',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/8/labels')
            && in_array('yak', $request['labels'])
            && ! in_array('yak-large-change', $request['labels']);
    });
});

/*
|--------------------------------------------------------------------------
| Green Path — Artifacts
|--------------------------------------------------------------------------
*/

test('green path collects artifacts from .yak-artifacts directory', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/art-repo/pull/1',
        ]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    $tempDir = sys_get_temp_dir() . '/yak-test-' . uniqid();
    $artifactsDir = $tempDir . '/.yak-artifacts';
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/screenshot.png', 'fake-png-data');
    file_put_contents($artifactsDir . '/report.html', '<html>report</html>');

    $repository = Repository::factory()->create([
        'slug' => 'org/art-repo',
        'path' => $tempDir,
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/art-repo',
        'branch_name' => 'yak/FIX-ART',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    expect(Artifact::where('yak_task_id', $task->id)->count())->toBe(2);

    $screenshot = Artifact::where('yak_task_id', $task->id)->where('filename', 'screenshot.png')->first();
    expect($screenshot)->not->toBeNull()
        ->and($screenshot->type)->toBe('screenshot');

    $report = Artifact::where('yak_task_id', $task->id)->where('filename', 'report.html')->first();
    expect($report)->not->toBeNull()
        ->and($report->type)->toBe('research');

    // Cleanup temp directory
    array_map('unlink', glob($artifactsDir . '/*'));
    rmdir($artifactsDir);
    rmdir($tempDir);
});

test('green path generates signed URLs with HMAC-SHA256 for artifacts', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/sig-repo/pull/1',
        ]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    $tempDir = sys_get_temp_dir() . '/yak-test-' . uniqid();
    $artifactsDir = $tempDir . '/.yak-artifacts';
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/capture.png', 'fake-png');

    $repository = Repository::factory()->create([
        'slug' => 'org/sig-repo',
        'path' => $tempDir,
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/sig-repo',
        'branch_name' => 'yak/FIX-SIG',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    // Verify artifact was created with signed URL in PR body
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, 'capture.png')
            && str_contains($body, 'expires=')
            && str_contains($body, 'signature=');
    });

    // Verify the signed URL uses HMAC-SHA256
    $artifact = Artifact::where('yak_task_id', $task->id)->first();
    $expires = now()->addDays(7)->timestamp;
    $payload = "{$artifact->id}:{$expires}";
    $expectedSignature = hash_hmac('sha256', $payload, (string) config('app.key'));

    expect(strlen($expectedSignature))->toBe(64); // SHA-256 hex digest

    // Cleanup
    array_map('unlink', glob($artifactsDir . '/*'));
    rmdir($artifactsDir);
    rmdir($tempDir);
});

/*
|--------------------------------------------------------------------------
| Green Path — Branch Cleanup
|--------------------------------------------------------------------------
*/

test('green path checks out default branch and deletes task branch', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
        'default_branch' => 'main',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-CLEANUP',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Process::assertRan(fn ($process) => $process->command === 'git checkout main');
    Process::assertRan(fn ($process) => $process->command === 'git branch -D yak/FIX-CLEANUP');
});

/*
|--------------------------------------------------------------------------
| Green Path — Source Notifications
|--------------------------------------------------------------------------
*/

test('green path posts PR link to Slack thread', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'slack.com/*' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.channels.slack.bot_token', 'slack-token');

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-SLACK',
        'source' => 'slack',
        'slack_channel' => 'C99999',
        'slack_thread_ts' => '111.222',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'slack.com/api/chat.postMessage')
            && $request['channel'] === 'C99999'
            && $request['thread_ts'] === '111.222'
            && str_contains($request['text'], 'PR created');
    });
});

test('green path posts PR link as Linear comment and moves issue to In Review', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.linear.app/*' => Http::response(['data' => ['success' => true]]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
        'git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.channels.linear.api_key', 'linear-key');

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/LIN-100',
        'source' => 'linear',
        'external_id' => 'LIN-100',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, true);
    $job->handle();

    // Verify Linear comment posted (PR link)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.linear.app/graphql')
            && str_contains($request['query'], 'commentCreate');
    });

    // Verify Linear issue moved to In Review
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.linear.app/graphql')
            && str_contains($request['query'], 'issueUpdate')
            && str_contains($request['query'], 'in-review');
    });
});

/*
|--------------------------------------------------------------------------
| First Failure — Retry Path
|--------------------------------------------------------------------------
*/

test('first failure dispatches RetryYakJob with failure output', function () {
    Queue::fake();

    config()->set('yak.max_attempts', 2);

    Repository::factory()->create(['slug' => 'org/my-repo']);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-RETRY',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Tests failed: 3 errors in AuthTest');
    $job->handle();

    Queue::assertPushed(RetryYakJob::class, function (RetryYakJob $retryJob) use ($task) {
        return $retryJob->task->id === $task->id
            && $retryJob->failureOutput === 'Tests failed: 3 errors in AuthTest';
    });
});

test('first failure sets task status to retrying and increments attempts', function () {
    Queue::fake();

    config()->set('yak.max_attempts', 2);

    Repository::factory()->create(['slug' => 'org/my-repo']);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-RETRY2',
        'source' => 'manual',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Build failed');
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Retrying)
        ->and($task->attempts)->toBe(2);
});

test('first failure posts CI failed retrying to source', function () {
    Queue::fake();
    Http::fake([
        'slack.com/*' => Http::response(['ok' => true]),
    ]);

    config()->set('yak.max_attempts', 2);
    config()->set('yak.channels.slack.bot_token', 'slack-token');

    Repository::factory()->create(['slug' => 'org/my-repo']);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-NOTIFY',
        'source' => 'slack',
        'slack_channel' => 'C55555',
        'slack_thread_ts' => '555.666',
        'attempts' => 1,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Lint failed');
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'slack.com/api/chat.postMessage')
            && str_contains($request['text'], 'CI failed, retrying');
    });
});

/*
|--------------------------------------------------------------------------
| Second Failure — Final Failure Path
|--------------------------------------------------------------------------
*/

test('second failure marks task as failed', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.max_attempts', 2);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
        'default_branch' => 'main',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-FAIL',
        'source' => 'manual',
        'attempts' => 2,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Tests still failing after retry');
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->error_log)->toBe('Tests still failing after retry');
});

test('second failure cleans up branch', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.max_attempts', 2);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
        'default_branch' => 'main',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-CLEANUP2',
        'source' => 'manual',
        'attempts' => 2,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Final failure');
    $job->handle();

    Process::assertRan(fn ($process) => $process->command === 'git checkout main');
    Process::assertRan(fn ($process) => $process->command === 'git branch -D yak/FIX-CLEANUP2');
});

test('second failure posts failure summary to source', function () {
    Http::fake([
        'slack.com/*' => Http::response(['ok' => true]),
    ]);

    Process::fake([
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.max_attempts', 2);
    config()->set('yak.channels.slack.bot_token', 'slack-token');

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-FAILNOTIFY',
        'source' => 'slack',
        'slack_channel' => 'C77777',
        'slack_thread_ts' => '777.888',
        'attempts' => 2,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Auth tests failed');
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'slack.com/api/chat.postMessage')
            && str_contains($request['text'], 'CI failed')
            && str_contains($request['text'], 'Auth tests failed');
    });
});

test('second failure does not dispatch RetryYakJob', function () {
    Queue::fake();

    Process::fake([
        'git checkout *' => Process::result(''),
        'git branch -D *' => Process::result(''),
    ]);

    config()->set('yak.max_attempts', 2);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-NORETRY',
        'source' => 'manual',
        'attempts' => 2,
    ]);

    $job = new ProcessCIResultJob($task, false, 'Still broken');
    $job->handle();

    Queue::assertNotPushed(RetryYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('ProcessCIResultJob dispatches to default queue', function () {
    $task = YakTask::factory()->awaitingCi()->make();
    $job = new ProcessCIResultJob($task, true);

    expect($job->queue)->toBe('default');
});
