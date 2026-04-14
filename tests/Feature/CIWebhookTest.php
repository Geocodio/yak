<?php

use App\Jobs\ProcessCIResultJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

/**
 * @param  array<string, mixed>  $payload
 */
function signDronePayload(array $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);
}

/*
|--------------------------------------------------------------------------
| GitHub CI Webhook — Route Registration
|--------------------------------------------------------------------------
*/

test('GitHub CI webhook route is always registered', function () {
    config()->set('yak.channels.github.webhook_secret', 'test-secret');

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
| GitHub CI Webhook — Non-yak branch ignored
|--------------------------------------------------------------------------
*/

test('GitHub CI webhook ignores non-yak branches', function () {
    Queue::fake();

    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);

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

/*
|--------------------------------------------------------------------------
| Drone CI Webhook — Route Registration
|--------------------------------------------------------------------------
*/

test('Drone CI webhook route is registered when credentials are present', function () {
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => 'drone-token',
    ]);

    // Re-register routes with Drone enabled
    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    // Route exists — returns 403 because no valid signature
    $this->postJson('/webhooks/ci/drone')->assertStatus(403);
});

test('Drone CI webhook route returns 404 when credentials are missing', function () {
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => null,
        'token' => null,
    ]);

    $this->postJson('/webhooks/ci/drone')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Drone CI Webhook — Signature Verification
|--------------------------------------------------------------------------
*/

test('Drone CI webhook rejects invalid signature', function () {
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => 'drone-token',
    ]);

    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    $this->postJson('/webhooks/ci/drone', [], [
        'X-Drone-Signature' => 'sha256=invalid',
    ])->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Drone CI Webhook — Build complete dispatches ProcessCIResultJob
|--------------------------------------------------------------------------
*/

test('Drone build complete dispatches ProcessCIResultJob', function () {
    Queue::fake();

    $secret = 'drone-secret-token';
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => $secret,
    ]);

    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    Repository::factory()->create([
        'slug' => 'org/drone-repo',
        'ci_system' => 'drone',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/drone-repo',
        'branch_name' => 'yak/fix-build',
    ]);

    $payload = [
        'build' => [
            'source' => 'yak/fix-build',
            'status' => 'success',
            'after' => 'commit-sha-123',
        ],
        'repo' => [
            'slug' => 'org/drone-repo',
        ],
    ];

    $signature = signDronePayload($payload, $secret);

    $this->postJson('/webhooks/ci/drone', $payload, [
        'X-Drone-Signature' => $signature,
    ])->assertOk()->assertJson(['ok' => true, 'dispatched' => true]);

    Queue::assertPushed(ProcessCIResultJob::class, function (ProcessCIResultJob $job) use ($task) {
        return $job->task->id === $task->id && $job->passed === true && $job->output === null;
    });
});

test('Drone build failure dispatches ProcessCIResultJob with passed=false', function () {
    Queue::fake();

    $secret = 'drone-secret-token';
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => $secret,
    ]);

    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    Repository::factory()->create([
        'slug' => 'org/drone-repo',
        'ci_system' => 'drone',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/drone-repo',
        'branch_name' => 'yak/fix-deploy',
    ]);

    $payload = [
        'build' => [
            'source' => 'yak/fix-deploy',
            'status' => 'failure',
            'after' => 'commit-sha-456',
            'output' => 'Build step "test" failed',
        ],
        'repo' => [
            'slug' => 'org/drone-repo',
        ],
    ];

    $signature = signDronePayload($payload, $secret);

    $this->postJson('/webhooks/ci/drone', $payload, [
        'X-Drone-Signature' => $signature,
    ])->assertOk();

    Queue::assertPushed(ProcessCIResultJob::class, function (ProcessCIResultJob $job) use ($task) {
        return $job->task->id === $task->id
            && $job->passed === false
            && $job->output === 'Build step "test" failed';
    });
});

/*
|--------------------------------------------------------------------------
| Drone CI Webhook — Non-yak branch ignored
|--------------------------------------------------------------------------
*/

test('Drone CI webhook ignores non-yak branches', function () {
    Queue::fake();

    $secret = 'drone-secret-token';
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => $secret,
    ]);

    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    $payload = [
        'build' => [
            'source' => 'main',
            'status' => 'success',
        ],
        'repo' => [
            'slug' => 'org/drone-repo',
        ],
    ];

    $signature = signDronePayload($payload, $secret);

    $this->postJson('/webhooks/ci/drone', $payload, [
        'X-Drone-Signature' => $signature,
    ])->assertOk()->assertJson(['skipped' => 'not a yak branch']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
});

/*
|--------------------------------------------------------------------------
| Drone CI Webhook — Wrong CI system ignored
|--------------------------------------------------------------------------
*/

test('Drone CI webhook ignores task when repo uses different CI system', function () {
    Queue::fake();

    $secret = 'drone-secret-token';
    config()->set('yak.channels.drone', [
        'driver' => 'drone',
        'url' => 'https://drone.example.com',
        'token' => $secret,
    ]);

    $provider = new ChannelServiceProvider($this->app);
    $provider->boot();

    Repository::factory()->create([
        'slug' => 'org/github-repo',
        'ci_system' => 'github_actions',
    ]);

    YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/github-repo',
        'branch_name' => 'yak/fix-github-thing',
    ]);

    $payload = [
        'build' => [
            'source' => 'yak/fix-github-thing',
            'status' => 'success',
        ],
        'repo' => [
            'slug' => 'org/github-repo',
        ],
    ];

    $signature = signDronePayload($payload, $secret);

    $this->postJson('/webhooks/ci/drone', $payload, [
        'X-Drone-Signature' => $signature,
    ])->assertOk()->assertJson(['skipped' => 'wrong CI system']);

    Queue::assertNotPushed(ProcessCIResultJob::class);
});
