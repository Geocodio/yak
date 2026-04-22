<?php

use App\Livewire\CostDashboard;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('yak.channels.github', array_merge(
        (array) config('yak.channels.github'),
        ['app_id' => '123', 'private_key' => 'key'],
    ));
});

function bootGitHubPrMergeRoutes(): void
{
    (new ChannelServiceProvider(app()))->boot();
}

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function signGitHubWebhookPayload(string $body, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $body, $secret);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postGitHubWebhook(mixed $test, array $payload, string $secret, string $event = 'pull_request'): TestResponse
{
    $body = json_encode($payload);

    return $test->call('POST', '/webhooks/github', content: $body, server: [
        'HTTP_X-Hub-Signature-256' => signGitHubWebhookPayload($body, $secret),
        'HTTP_X-GitHub-Event' => $event,
        'CONTENT_TYPE' => 'application/json',
    ]);
}

/*
|--------------------------------------------------------------------------
| Migration
|--------------------------------------------------------------------------
*/

test('tasks table has pr_merged_at and pr_closed_at columns', function () {
    $task = YakTask::factory()->success()->create();

    $task->update([
        'pr_merged_at' => now(),
        'pr_closed_at' => now(),
    ]);

    $task->refresh();

    expect($task->pr_merged_at)->not->toBeNull()
        ->and($task->pr_closed_at)->not->toBeNull();
});

test('pr_merged_at and pr_closed_at are nullable by default', function () {
    $task = YakTask::factory()->success()->create();

    expect($task->pr_merged_at)->toBeNull()
        ->and($task->pr_closed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Webhook — Signature Verification
|--------------------------------------------------------------------------
*/

test('GitHub webhook rejects invalid signature', function () {
    config()->set('yak.channels.github.webhook_secret', 'test-secret');
    bootGitHubPrMergeRoutes();

    $this->postJson('/webhooks/github', [], [
        'X-Hub-Signature-256' => 'sha256=invalid',
        'X-GitHub-Event' => 'pull_request',
    ])->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Webhook — pull_request.closed (merged)
|--------------------------------------------------------------------------
*/

test('PR merged updates pr_merged_at on task', function () {
    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubPrMergeRoutes();

    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/42',
    ]);

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => 'https://github.com/org/repo/pull/42',
            'merged' => true,
            'merged_at' => '2026-04-11T07:00:00Z',
            'closed_at' => '2026-04-11T07:00:00Z',
        ],
    ];

    $response = postGitHubWebhook($this, $payload, $secret);

    $response->assertOk()->assertJson(['ok' => true, 'updated' => true]);

    $task->refresh();
    expect($task->pr_merged_at)->not->toBeNull()
        ->and($task->pr_closed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Webhook — pull_request.closed (not merged)
|--------------------------------------------------------------------------
*/

test('PR closed without merge updates pr_closed_at on task', function () {
    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubPrMergeRoutes();

    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/43',
    ]);

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => 'https://github.com/org/repo/pull/43',
            'merged' => false,
            'closed_at' => '2026-04-11T07:00:00Z',
        ],
    ];

    $response = postGitHubWebhook($this, $payload, $secret);

    $response->assertOk()->assertJson(['ok' => true, 'updated' => true]);

    $task->refresh();
    expect($task->pr_closed_at)->not->toBeNull()
        ->and($task->pr_merged_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Webhook — Skips non-matching events
|--------------------------------------------------------------------------
*/

test('webhook skips unsupported events', function () {
    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubPrMergeRoutes();

    $payload = ['action' => 'created'];

    $response = postGitHubWebhook($this, $payload, $secret, 'issue_comment');

    $response->assertOk()->assertJson(['skipped' => 'unhandled event: issue_comment']);
});

test('webhook skips when no task matches PR URL', function () {
    $secret = 'github-webhook-secret';
    config()->set('yak.channels.github.webhook_secret', $secret);
    bootGitHubPrMergeRoutes();

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => 'https://github.com/org/repo/pull/999',
            'merged' => true,
            'merged_at' => '2026-04-11T07:00:00Z',
        ],
    ];

    $response = postGitHubWebhook($this, $payload, $secret);

    $response->assertOk()->assertJson(['skipped' => 'no task found for PR']);
});

/*
|--------------------------------------------------------------------------
| Factory States
|--------------------------------------------------------------------------
*/

test('merged factory state sets pr_merged_at', function () {
    $task = YakTask::factory()->merged()->create();

    expect($task->pr_merged_at)->not->toBeNull()
        ->and($task->pr_url)->not->toBeNull();
});

test('closedWithoutMerge factory state sets pr_closed_at', function () {
    $task = YakTask::factory()->closedWithoutMerge()->create();

    expect($task->pr_closed_at)->not->toBeNull()
        ->and($task->pr_url)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Dashboard — Merge Rate
|--------------------------------------------------------------------------
*/

test('cost dashboard merge rate computed property returns data by repo', function () {
    YakTask::factory()->merged()->create(['repo' => 'org/repo-a']);
    YakTask::factory()->merged()->create(['repo' => 'org/repo-a']);
    YakTask::factory()->closedWithoutMerge()->create(['repo' => 'org/repo-a']);
    YakTask::factory()->merged()->create(['repo' => 'org/repo-b']);

    $component = Livewire\Livewire::test(CostDashboard::class);

    $instance = $component->instance();
    $mergeRate = $instance->mergeRate;

    expect($mergeRate)->toHaveCount(2);

    $repoA = $mergeRate->firstWhere('repo', 'org/repo-a');
    expect($repoA->total_prs)->toBe(3)
        ->and($repoA->merged_count)->toBe(2)
        ->and($repoA->closed_count)->toBe(1)
        ->and($repoA->merge_rate)->toBe(67.0);

    $repoB = $mergeRate->firstWhere('repo', 'org/repo-b');
    expect($repoB->total_prs)->toBe(1)
        ->and($repoB->merged_count)->toBe(1)
        ->and($repoB->merge_rate)->toBe(100.0);
});
