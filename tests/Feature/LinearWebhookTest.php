<?php

use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\LinearOauthConnection;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Sign a Linear webhook payload using HMAC-SHA256.
 */
function signLinearPayload(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

/**
 * The default Yak actor id and workspace used by most webhook tests. Kept
 * stable so payloads can reference them directly.
 */
const TEST_YAK_ACTOR_ID = 'yak-actor-id';
const TEST_WORKSPACE_ID = 'workspace-uuid-001';

/**
 * Build a Linear Issue update webhook payload simulating an assignment
 * to the Yak OAuth app.
 *
 * @param  array<string, mixed>  $overrides
 */
function linearAssignmentPayload(array $overrides = []): string
{
    $issueId = $overrides['issueId'] ?? 'issue-uuid-123';
    $identifier = $overrides['identifier'] ?? 'ENG-42';
    $title = $overrides['title'] ?? 'Fix the broken auth flow';
    $description = $overrides['description'] ?? 'The login page returns 500 errors intermittently.';
    $url = $overrides['url'] ?? 'https://linear.app/team/issue/ENG-42';
    $assigneeId = $overrides['assigneeId'] ?? TEST_YAK_ACTOR_ID;
    $previousAssigneeId = $overrides['previousAssigneeId'] ?? null;
    $workspaceId = $overrides['workspaceId'] ?? TEST_WORKSPACE_ID;
    $labels = $overrides['labels'] ?? [];

    $payload = [
        'type' => 'Issue',
        'action' => 'update',
        'organizationId' => $workspaceId,
        'data' => [
            'id' => $issueId,
            'identifier' => $identifier,
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'labels' => $labels,
            'assignee' => $assigneeId !== null ? ['id' => $assigneeId, 'name' => 'Yak'] : null,
        ],
        'updatedFrom' => [
            'assigneeId' => $previousAssigneeId,
        ],
    ];

    if (isset($overrides['type'])) {
        $payload['type'] = $overrides['type'];
    }

    if (isset($overrides['action'])) {
        $payload['action'] = $overrides['action'];
    }

    if (array_key_exists('omitUpdatedFrom', $overrides) && $overrides['omitUpdatedFrom']) {
        unset($payload['updatedFrom']);
    }

    return (string) json_encode($payload);
}

/**
 * Seed an active Linear OAuth connection so the webhook controller can
 * resolve it by workspace id and compare against Yak's actor id.
 */
function linearConnection(string $workspaceId = TEST_WORKSPACE_ID, string $actorId = TEST_YAK_ACTOR_ID): LinearOauthConnection
{
    return LinearOauthConnection::factory()->create([
        'workspace_id' => $workspaceId,
        'installer_user_id' => $actorId,
    ]);
}

/**
 * Enable the Linear channel and re-register routes.
 */
function enableLinearChannel(): string
{
    $secret = 'test-linear-webhook-secret';

    config()->set('yak.channels.linear', [
        'driver' => 'linear',
        'webhook_secret' => $secret,
        'oauth_client_id' => 'cid-test',
        'oauth_client_secret' => 'csecret-test',
        'oauth_redirect_uri' => 'http://localhost/auth/linear/callback',
        'oauth_scopes' => 'read,write',
    ]);

    // Re-register routes so the Linear route is available
    (new ChannelServiceProvider(app()))->boot();

    return $secret;
}

/*
|--------------------------------------------------------------------------
| Signature Verification
|--------------------------------------------------------------------------
*/

it('rejects requests with invalid Linear signature', function () {
    $secret = enableLinearChannel();
    linearConnection();
    $body = linearAssignmentPayload();

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => 'invalid_signature',
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
});

it('rejects requests with missing Linear signature', function () {
    enableLinearChannel();
    linearConnection();
    $body = linearAssignmentPayload();

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Assigning to Yak Creates Fix Task
|--------------------------------------------------------------------------
*/

it('creates a fix task when an issue is assigned to Yak', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-001',
        'identifier' => 'ENG-42',
        'title' => 'Fix the broken auth flow',
        'description' => 'The login page returns 500 errors intermittently.',
        'url' => 'https://linear.app/team/issue/ENG-42',
    ]);
    $signature = signLinearPayload($body, $secret);

    $response = $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ]);

    $response->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->source)->toBe('linear');
    expect($task->external_id)->toBe('LINEAR-ENG-42');
    expect($task->external_url)->toBe('https://linear.app/team/issue/ENG-42');
    expect($task->repo)->toBe('my-app');
    expect($task->mode)->toBe(TaskMode::Fix);
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->description)->toContain('Fix the broken auth flow');
    expect($task->description)->toContain('The login page returns 500 errors intermittently.');

    $context = json_decode((string) $task->context, true);
    expect($context['title'])->toBe('Fix the broken auth flow');
    expect($context['description'])->toBe('The login page returns 500 errors intermittently.');
    expect($context['linear_issue_id'])->toBe('issue-uuid-001');
    expect($context['linear_issue_identifier'])->toBe('ENG-42');
    expect($context['linear_issue_url'])->toBe('https://linear.app/team/issue/ENG-42');

    Queue::assertPushed(RunYakJob::class, function (RunYakJob $job) use ($task) {
        return $job->task->id === $task->id;
    });
});

/*
|--------------------------------------------------------------------------
| Research Mode (label or title hint) still works
|--------------------------------------------------------------------------
*/

it('creates a research task when the issue has a research label at assignment time', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-002',
        'labels' => [['id' => 'label-research-id', 'name' => 'research']],
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->mode)->toBe(TaskMode::Research);
});

it('creates a research task when "research" appears in the issue title', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-002a',
        'title' => 'Research: audit deprecated field usage',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->mode)->toBe(TaskMode::Research);
});

it('matches "research" in the title regardless of punctuation or casing', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-002b',
        'title' => '[RESEARCH] investigate memory leak',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::first()->mode)->toBe(TaskMode::Research);
});

it('does not match research as a substring of another word', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->default()->create(['slug' => 'my-app']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-002c',
        'title' => 'Fix researcher profile page bug',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::first()->mode)->toBe(TaskMode::Fix);
});

/*
|--------------------------------------------------------------------------
| Repo Detection
|--------------------------------------------------------------------------
*/

it('detects repo from issue description using repo: syntax', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->create(['slug' => 'acme/api']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-003',
        'description' => 'The auth middleware is broken. repo: acme/api',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->repo)->toBe('acme/api');
});

it('falls back to default repo when no repo mentioned in description', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->create(['slug' => 'other-repo']);
    Repository::factory()->default()->create(['slug' => 'default-repo']);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-004',
        'description' => 'Just a plain bug description without repo info.',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->repo)->toBe('default-repo');
});

/*
|--------------------------------------------------------------------------
| Assignment change edge cases
|--------------------------------------------------------------------------
*/

it('ignores events where Yak is unassigned from the issue', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-005',
        'identifier' => 'ENG-99',
        'assigneeId' => null,
        'previousAssigneeId' => TEST_YAK_ACTOR_ID,
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

it('ignores events where the issue is assigned to someone other than Yak', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-006',
        'identifier' => 'ENG-100',
        'assigneeId' => 'some-other-user-id',
        'previousAssigneeId' => null,
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

it('ignores unrelated field updates on an issue already assigned to Yak', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-006b',
        'identifier' => 'ENG-101',
        'assigneeId' => TEST_YAK_ACTOR_ID,
        'previousAssigneeId' => TEST_YAK_ACTOR_ID,
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

it('ignores webhooks from workspaces with no matching connection', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-006c',
        'workspaceId' => 'unknown-workspace-id',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Acknowledgment and State Management
|--------------------------------------------------------------------------
*/

it('dispatches acknowledgment notification on pickup', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    Repository::factory()->default()->create();

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-007',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) {
        return $job->type === NotificationType::Acknowledgment;
    });
});

/*
|--------------------------------------------------------------------------
| Notification Driver
|--------------------------------------------------------------------------
*/

it('LinearNotificationDriver posts comments to Linear issues', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    LinearOauthConnection::factory()->create([
        'access_token' => 'lin_oauth_access_test',
    ]);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-notify',
    ]);

    $driver = new LinearNotificationDriver;
    $driver->send($task, NotificationType::Progress, 'Working on this issue now...');

    assertLinearComment('Working on this issue now...');
    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'linear.app/graphql')
            && $request->header('Authorization')[0] === 'Bearer lin_oauth_access_test';
    });
});

it('LinearNotificationDriver uses UUID from context when external_id is a LINEAR- identifier', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-123',
        'context' => json_encode([
            'linear_issue_id' => 'uuid-from-context',
            'linear_issue_identifier' => 'ENG-123',
        ]),
    ]);

    $driver = new LinearNotificationDriver;
    $driver->send($task, NotificationType::Progress, 'Using UUID from context');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['variables']['issueId'] ?? null) === 'uuid-from-context';
    });
});

it('LinearNotificationDriver posts result to Linear issues', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-result',
    ]);

    $driver = new LinearNotificationDriver;
    $driver->send($task, NotificationType::Result, 'PR created: https://github.com/org/repo/pull/42');

    assertLinearComment('PR created');
});

it('LinearNotificationDriver updates issue state', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-state',
    ]);

    $driver = new LinearNotificationDriver;
    config()->set('yak.channels.linear.done_state_id', 'done-state-uuid');
    $driver->send($task, NotificationType::Result, 'Done!');

    assertLinearStateUpdate('done-state-uuid');
});

it('LinearNotificationDriver skips when no OAuth connection exists', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-nokey',
    ]);

    $driver = new LinearNotificationDriver;
    $driver->send($task, NotificationType::Progress, 'should not be sent');

    Http::assertNothingSent();
});

it('LinearNotificationDriver skips when the OAuth connection is disconnected', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    LinearOauthConnection::factory()->disconnected()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-disc',
    ]);

    (new LinearNotificationDriver)
        ->send($task, NotificationType::Progress, 'should not be sent');

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Non-Issue Events Ignored
|--------------------------------------------------------------------------
*/

it('ignores non-Issue type events', function () {
    $secret = enableLinearChannel();
    Queue::fake();

    $body = (string) json_encode([
        'type' => 'Comment',
        'action' => 'create',
        'data' => ['id' => 'comment-123'],
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

/*
|--------------------------------------------------------------------------
| Duplicate Prevention
|--------------------------------------------------------------------------
*/

it('does not create duplicate task for same Linear issue', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    Repository::factory()->default()->create();

    // Create existing task for this issue
    YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-42',
    ]);

    $body = linearAssignmentPayload([
        'issueId' => 'issue-uuid-dup',
        'identifier' => 'ENG-42',
    ]);
    $signature = signLinearPayload($body, $secret);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::where('source', 'linear')->where('external_id', 'LINEAR-ENG-42')->count())->toBe(1);
    Queue::assertNotPushed(RunYakJob::class);
});
