<?php

use App\Jobs\ProcessCIResultJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('yak.channels.github', array_merge(
        (array) config('yak.channels.github'),
        ['app_id' => '123', 'private_key' => 'key'],
    ));
});

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

/**
 * @param  array<string, mixed>  $payload
 */
function signGitHubPayload(array $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
}

function bootGitHubRoutes(): void
{
    (new ChannelServiceProvider(app()))->boot();
}

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — Route Registration
|--------------------------------------------------------------------------
*/

test('GitHub CI webhook route is always registered', function () {
    config()->set('yak.channels.github.webhook_secret', 'test-secret');
    bootGitHubRoutes();

    // Route exists — returns 403 because no valid signature
    $this->postJson('/webhooks/ci/github')->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — Signature Verification
|--------------------------------------------------------------------------
*/

test('GitHub CI webhook rejects invalid signature', function () {
    config()->set('yak.channels.github.webhook_secret', 'test-secret');
    bootGitHubRoutes();

    $this->postJson('/webhooks/ci/github', [], [
        'X-Hub-Signature-256' => 'sha256=invalid',
        'X-GitHub-Event' => 'check_suite',
    ])->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — check_suite.completed dispatches ProcessCIResultJob
|--------------------------------------------------------------------------
*/

test('GitHub check_suite.completed dispatches ProcessCIResultJob', function () {
    Queue::fake();

    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubRoutes();

    $repo = Repository::factory()->create([
        'slug' => 'org/my-repo',
        'ci_system' => 'github_actions',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/fix-login',
    ]);

    $payload = [
        'action' => 'completed',
        'check_suite' => [
            'head_branch' => 'yak/fix-login',
            'conclusion' => 'success',
            'head_sha' => 'abc123',
        ],
        'repository' => [
            'full_name' => 'org/my-repo',
        ],
    ];

    $signature = signGitHubPayload($payload, $secret);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Event' => 'check_suite',
    ])->assertOk()->assertJson(['ok' => true, 'dispatched' => true]);

    Queue::assertPushed(ProcessCIResultJob::class, function (ProcessCIResultJob $job) use ($task) {
        return $job->task->id === $task->id && $job->passed === true && $job->output === null;
    });
});

test('GitHub check_suite.completed with failure fetches check_run output from API', function () {
    Queue::fake();

    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    config()->set('yak.channels.github.installation_id', 12345);
    bootGitHubRoutes();

    // Pre-populate a cached installation token so we skip JWT generation
    GitHubInstallationToken::create([
        'installation_id' => 12345,
        'token' => 'ghs_fake',
        'expires_at' => now()->addHour(),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'ci_system' => 'github_actions',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/fix-auth',
    ]);

    Http::fake([
        'https://api.github.com/repos/org/my-repo/commits/def456/check-runs*' => Http::response([
            'check_runs' => [
                [
                    'name' => 'tests',
                    'conclusion' => 'failure',
                    'html_url' => 'https://github.com/org/my-repo/runs/1',
                    'output' => [
                        'title' => '3 tests failed',
                        'summary' => 'AuthTest::testLogin failed on line 42',
                        'text' => 'Expected: true, Actual: false',
                    ],
                ],
                [
                    'name' => 'lint',
                    'conclusion' => 'success',
                ],
            ],
        ]),
    ]);

    $payload = [
        'action' => 'completed',
        'check_suite' => [
            'head_branch' => 'yak/fix-auth',
            'conclusion' => 'failure',
            'head_sha' => 'def456',
        ],
        'repository' => [
            'full_name' => 'org/my-repo',
        ],
    ];

    $signature = signGitHubPayload($payload, $secret);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Event' => 'check_suite',
    ])->assertOk();

    Queue::assertPushed(ProcessCIResultJob::class, function (ProcessCIResultJob $job) use ($task) {
        return $job->task->id === $task->id
            && $job->passed === false
            && str_contains((string) $job->output, 'tests')
            && str_contains((string) $job->output, '3 tests failed')
            && str_contains((string) $job->output, 'AuthTest::testLogin failed')
            && ! str_contains((string) $job->output, 'lint'); // passed runs excluded
    });
});

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — failed jobs get one re-run before counting
|--------------------------------------------------------------------------
*/

function setUpFailingGitHubActionsTask(): YakTask
{
    config()->set('yak.channels.github.webhook_secret', 'github-webhook-secret');
    config()->set('yak.channels.github.installation_id', 12345);
    bootGitHubRoutes();

    GitHubInstallationToken::create([
        'installation_id' => 12345,
        'token' => 'ghs_fake',
        'expires_at' => now()->addHour(),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'ci_system' => 'github_actions',
    ]);

    return YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/fix-flake',
    ]);
}

function postFailedCheckSuite(): TestResponse
{
    $payload = [
        'action' => 'completed',
        'check_suite' => [
            'head_branch' => 'yak/fix-flake',
            'conclusion' => 'failure',
            'head_sha' => 'abc123',
        ],
        'repository' => ['full_name' => 'org/my-repo'],
    ];

    return test()->postJson('/webhooks/ci/github', $payload, [
        'X-Hub-Signature-256' => signGitHubPayload($payload, 'github-webhook-secret'),
        'X-GitHub-Event' => 'check_suite',
    ]);
}

/**
 * @return array<string, mixed>
 */
function workflowRunsResponse(int $runAttempt): array
{
    return ['workflow_runs' => [
        ['id' => 77, 'name' => 'CI', 'status' => 'completed', 'conclusion' => 'failure', 'run_attempt' => $runAttempt],
        ['id' => 78, 'name' => 'Lint', 'status' => 'completed', 'conclusion' => 'success', 'run_attempt' => 1],
    ]];
}

test('a first failed attempt re-runs the failed jobs instead of reporting the failure', function () {
    Queue::fake();
    $task = setUpFailingGitHubActionsTask();

    Http::fake([
        'https://api.github.com/repos/org/my-repo/actions/runs/77/rerun-failed-jobs' => Http::response([], 201),
        'https://api.github.com/repos/org/my-repo/actions/runs*' => Http::response(workflowRunsResponse(runAttempt: 1)),
    ]);

    postFailedCheckSuite()->assertOk()->assertJson(['skipped' => 'rerunning failed jobs']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/actions/runs/77/rerun-failed-jobs'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/runs/78/'));

    expect(TaskLog::where('yak_task_id', $task->id)->where('message', 'like', 'CI failed, re-running%')->exists())->toBeTrue();
});

test('a duplicate failure event while the re-run is pending is not reported', function () {
    Queue::fake();
    setUpFailingGitHubActionsTask();

    Http::fake([
        'https://api.github.com/repos/org/my-repo/actions/runs/77/rerun-failed-jobs' => Http::response([], 201),
        'https://api.github.com/repos/org/my-repo/actions/runs*' => Http::response(workflowRunsResponse(runAttempt: 1)),
    ]);

    postFailedCheckSuite()->assertOk();
    postFailedCheckSuite()->assertOk()->assertJson(['skipped' => 'rerunning failed jobs']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
    Http::assertSentCount(3);
});

test('a failed re-run reports the failure with the job log excerpt', function () {
    Queue::fake();
    $task = setUpFailingGitHubActionsTask();

    $log = implode("\n", [
        '2026-09-30T08:40:01.0000000Z ##[group]Run php artisan test tests/Browser',
        '2026-09-30T08:40:59.0000000Z FAILED  Tests\\Browser\\LoginTest > it logs in',
        '2026-09-30T08:40:59.1000000Z Timed out waiting for [data-testid=dashboard]',
        '2026-09-30T08:41:00.0000000Z ##[error]Process completed with exit code 1.',
        '2026-09-30T08:41:01.0000000Z ##[group]Run docker compose down -v',
        '2026-09-30T08:41:05.0000000Z Container removed',
    ]);

    Http::fake([
        'https://api.github.com/repos/org/my-repo/actions/runs*' => Http::response(workflowRunsResponse(runAttempt: 2)),
        'https://api.github.com/repos/org/my-repo/actions/jobs/555/logs' => Http::response($log),
        'https://api.github.com/repos/org/my-repo/commits/abc123/check-runs*' => Http::response(['check_runs' => [[
            'id' => 555,
            'name' => 'Test (Browser)',
            'conclusion' => 'failure',
            'html_url' => 'https://github.com/org/my-repo/actions/runs/77/job/555',
            'app' => ['slug' => 'github-actions'],
            'output' => ['title' => null, 'summary' => null, 'text' => null],
        ]]]),
    ]);

    postFailedCheckSuite()->assertOk()->assertJson(['dispatched' => true]);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->task->is($task)
        && str_contains((string) $job->output, '## Test (Browser)')
        && str_contains((string) $job->output, 'Timed out waiting for [data-testid=dashboard]')
        && str_contains((string) $job->output, '##[error]Process completed with exit code 1.')
        && ! str_contains((string) $job->output, 'Container removed')
        && ! str_contains((string) $job->output, '2026-09-30T08:40'));
});

test('a re-run GitHub refuses falls through to reporting the failure', function () {
    Queue::fake();
    setUpFailingGitHubActionsTask();

    Http::fake([
        'https://api.github.com/repos/org/my-repo/actions/runs/77/rerun-failed-jobs' => Http::response(['message' => 'Forbidden'], 403),
        'https://api.github.com/repos/org/my-repo/actions/runs*' => Http::response(workflowRunsResponse(runAttempt: 1)),
        'https://api.github.com/repos/org/my-repo/commits/abc123/check-runs*' => Http::response(['check_runs' => []]),
    ]);

    postFailedCheckSuite()->assertOk()->assertJson(['dispatched' => true]);

    Queue::assertPushed(ProcessCIResultJob::class);
});

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — Non-yak branch ignored
|--------------------------------------------------------------------------
*/

test('GitHub CI webhook ignores non-yak branches', function () {
    Queue::fake();

    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubRoutes();

    $payload = [
        'action' => 'completed',
        'check_suite' => [
            'head_branch' => 'feature/my-feature',
            'conclusion' => 'success',
        ],
        'repository' => [
            'full_name' => 'org/my-repo',
        ],
    ];

    $signature = signGitHubPayload($payload, $secret);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Event' => 'check_suite',
    ])->assertOk()->assertJson(['skipped' => 'not a yak branch']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
});

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — Wrong CI system ignored
|--------------------------------------------------------------------------
*/

test('GitHub CI webhook ignores task when repo uses different CI system', function () {
    Queue::fake();

    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubRoutes();

    Repository::factory()->create([
        'slug' => 'org/drone-repo',
        'ci_system' => 'drone',
    ]);

    YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/drone-repo',
        'branch_name' => 'yak/fix-drone-thing',
    ]);

    $payload = [
        'action' => 'completed',
        'check_suite' => [
            'head_branch' => 'yak/fix-drone-thing',
            'conclusion' => 'success',
        ],
        'repository' => [
            'full_name' => 'org/drone-repo',
        ],
    ];

    $signature = signGitHubPayload($payload, $secret);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-Hub-Signature-256' => $signature,
        'X-GitHub-Event' => 'check_suite',
    ])->assertOk()->assertJson(['skipped' => 'wrong CI system']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
});
