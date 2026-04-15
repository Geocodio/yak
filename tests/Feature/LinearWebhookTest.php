<?php

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

function signLinearPayload(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

const TEST_YAK_ACTOR_ID = 'yak-actor-id';
const TEST_WORKSPACE_ID = 'workspace-uuid-001';

function enableLinearChannel(): string
{
    $secret = 'test-linear-webhook-secret';

    config()->set('yak.channels.linear', [
        'driver' => 'linear',
        'webhook_secret' => $secret,
        'oauth_client_id' => 'cid-test',
        'oauth_client_secret' => 'csecret-test',
        'oauth_redirect_uri' => 'http://localhost/auth/linear/callback',
        'oauth_scopes' => 'read,write,app:assignable,app:mentionable',
    ]);

    (new ChannelServiceProvider(app()))->boot();

    return $secret;
}

function linearConnection(string $workspaceId = TEST_WORKSPACE_ID, string $actorId = TEST_YAK_ACTOR_ID): LinearOauthConnection
{
    return LinearOauthConnection::factory()->create([
        'workspace_id' => $workspaceId,
        'installer_user_id' => $actorId,
    ]);
}

function agentSessionCreatedPayload(array $overrides = []): string
{
    return (string) json_encode([
        'type' => 'AgentSessionEvent',
        'action' => 'created',
        'organizationId' => $overrides['workspaceId'] ?? TEST_WORKSPACE_ID,
        'agentSession' => [
            'id' => $overrides['sessionId'] ?? 'session-uuid-001',
            'issue' => [
                'id' => $overrides['issueId'] ?? 'issue-uuid-001',
                'identifier' => $overrides['identifier'] ?? 'ENG-42',
                'title' => $overrides['title'] ?? 'Fix the broken auth flow',
                'description' => $overrides['description'] ?? 'Intermittent 500 on login.',
                'url' => $overrides['url'] ?? 'https://linear.app/team/issue/ENG-42',
            ],
            'promptContext' => $overrides['promptContext'] ?? '',
        ],
    ]);
}

function agentSessionPromptedPayload(array $overrides = []): string
{
    return (string) json_encode([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => $overrides['workspaceId'] ?? TEST_WORKSPACE_ID,
        'agentSession' => [
            'id' => $overrides['sessionId'] ?? 'session-uuid-001',
        ],
        'agentActivity' => [
            'body' => $overrides['body'] ?? 'A follow-up message from the user',
        ],
    ]);
}

function postLinearWebhook(string $body, string $secret, string $eventHeader = 'AgentSessionEvent')
{
    $signature = signLinearPayload($body, $secret);

    return test()->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Signature' => $signature,
        'HTTP_Linear-Event' => $eventHeader,
        'CONTENT_TYPE' => 'application/json',
    ]);
}

// --- Signature verification ---

it('rejects requests with invalid Linear signature', function () {
    enableLinearChannel();
    linearConnection();

    test()->call('POST', '/webhooks/linear', content: agentSessionCreatedPayload(), server: [
        'HTTP_Linear-Signature' => 'invalid',
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
});

it('rejects requests with missing Linear signature', function () {
    enableLinearChannel();
    linearConnection();

    test()->call('POST', '/webhooks/linear', content: agentSessionCreatedPayload(), server: [
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'CONTENT_TYPE' => 'application/json',
    ])->assertForbidden();
});

// --- AgentSessionEvent.created creates a task ---

it('creates a fix task when an AgentSessionEvent.created webhook arrives', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create(['slug' => 'my-app']);

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    $task = YakTask::first();
    expect($task)->not->toBeNull();
    expect($task->source)->toBe('linear');
    expect($task->external_id)->toBe('LINEAR-ENG-42');
    expect($task->external_url)->toBe('https://linear.app/team/issue/ENG-42');
    expect($task->repo)->toBe('my-app');
    expect($task->mode)->toBe(TaskMode::Fix);
    expect($task->status)->toBe(TaskStatus::Pending);
    expect($task->linear_agent_session_id)->toBe('session-uuid-001');
    expect($task->description)->toContain('Fix the broken auth flow');

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->id === $task->id);
});

it('dispatches an acknowledgment notification on pickup', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    Queue::assertPushed(
        SendNotificationJob::class,
        fn (SendNotificationJob $job) => $job->type === NotificationType::Acknowledgment,
    );
});

it('detects research mode from "research" in the issue title', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload([
        'title' => '[Research] audit deprecated fields',
    ]), $secret)->assertSuccessful();

    expect(YakTask::first()->mode)->toBe(TaskMode::Research);
});

it('detects repo from "repo:" mention in the issue description', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->create(['slug' => 'acme/api']);

    postLinearWebhook(agentSessionCreatedPayload([
        'description' => 'The auth middleware is broken. repo: acme/api',
    ]), $secret)->assertSuccessful();

    expect(YakTask::first()->repo)->toBe('acme/api');
});

it('falls back to the default repo when no repo is mentioned', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->create(['slug' => 'other-repo']);
    Repository::factory()->default()->create(['slug' => 'default-repo']);

    postLinearWebhook(agentSessionCreatedPayload(['description' => 'No repo mentioned here.']), $secret)
        ->assertSuccessful();

    expect(YakTask::first()->repo)->toBe('default-repo');
});

it('does not create a duplicate task for the same Linear issue', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-42',
    ]);

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    expect(YakTask::where('external_id', 'LINEAR-ENG-42')->count())->toBe(1);
    Queue::assertNotPushed(RunYakJob::class);
});

it('ignores webhooks from workspaces with no matching connection', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    postLinearWebhook(agentSessionCreatedPayload(['workspaceId' => 'unknown-ws']), $secret)
        ->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

// --- Ignored event types ---

it('ignores unrelated webhook event headers', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();

    postLinearWebhook(agentSessionCreatedPayload(), $secret, eventHeader: 'Issue')
        ->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});
