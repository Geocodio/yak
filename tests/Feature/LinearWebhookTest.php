<?php

use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
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

it('posts an acknowledgment activity synchronously on pickup', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    // The ack now goes out synchronously (with a personality timeout)
    // rather than through SendNotificationJob, so we assert against the
    // HTTP request to Linear's Agent Activity API directly.
    assertLinearActivity();
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

it('dispatches ResearchYakJob (not RunYakJob) for research-mode tasks', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload([
        'title' => 'Research: inventory all jobs',
    ]), $secret)->assertSuccessful();

    Queue::assertPushed(ResearchYakJob::class);
    Queue::assertNotPushed(RunYakJob::class);
});

it('dispatches RunYakJob for fix-mode tasks', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    Queue::assertPushed(RunYakJob::class);
    Queue::assertNotPushed(ResearchYakJob::class);
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

// --- AgentSessionEvent.prompted responds with a polite error ---

it('responds to prompted events with an error activity pointing to the dashboard', function () {
    $secret = enableLinearChannel();
    linearConnection();
    LinearOauthConnection::query()->delete(); // recreate with known token
    LinearOauthConnection::factory()->create([
        'workspace_id' => TEST_WORKSPACE_ID,
        'installer_user_id' => TEST_YAK_ACTOR_ID,
    ]);
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    postLinearWebhook(agentSessionPromptedPayload(['sessionId' => 'session-xyz']), $secret)
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'linear.app/graphql')) {
            return false;
        }
        $vars = $request->data()['variables'] ?? [];

        return ($vars['input']['agentSessionId'] ?? null) === 'session-xyz'
            && ($vars['input']['content']['type'] ?? null) === 'error';
    });
});

it('posts a thought activity to Linear within the webhook response (fast ack)', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(['sessionId' => 'session-xyz']), $secret)
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'linear.app/graphql')) {
            return false;
        }
        $body = $request->data();
        $vars = $body['variables'] ?? [];

        return ($vars['input']['agentSessionId'] ?? null) === 'session-xyz'
            && ($vars['input']['content']['type'] ?? null) === 'thought';
    });
});

// --- Notification Driver posts agent activities ---

it('LinearNotificationDriver posts a thought activity for progress notifications', function () {
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    LinearOauthConnection::factory()->create(['access_token' => 'lin_oauth_access_test']);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-99',
        'linear_agent_session_id' => 'session-progress',
    ]);

    app(LinearNotificationDriver::class)
        ->send($task, NotificationType::Progress, 'Making progress on the bug.');

    Http::assertSent(function ($request): bool {
        $vars = $request->data()['variables'] ?? [];

        return ($vars['input']['agentSessionId'] ?? null) === 'session-progress'
            && ($vars['input']['content']['type'] ?? null) === 'thought'
            && str_contains($vars['input']['content']['body'] ?? '', 'Making progress');
    });
});

it('LinearNotificationDriver posts a response activity for result notifications', function () {
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-100',
        'linear_agent_session_id' => 'session-result',
    ]);

    app(LinearNotificationDriver::class)
        ->send($task, NotificationType::Result, 'PR opened: https://github.com/org/repo/pull/42');

    Http::assertSent(function ($request): bool {
        $vars = $request->data()['variables'] ?? [];

        return ($vars['input']['content']['type'] ?? null) === 'response';
    });
});

it('LinearNotificationDriver posts an error activity for error notifications', function () {
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-101',
        'linear_agent_session_id' => 'session-error',
    ]);

    app(LinearNotificationDriver::class)
        ->send($task, NotificationType::Error, 'Task failed: CI stayed red after retry.');

    Http::assertSent(function ($request): bool {
        $vars = $request->data()['variables'] ?? [];

        return ($vars['input']['content']['type'] ?? null) === 'error';
    });
});

it('LinearNotificationDriver still updates the Linear issue state on result', function () {
    Http::fake(['*' => Http::response(['data' => ['issueUpdate' => ['success' => true]]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', 'done-state-uuid');

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-102',
        'linear_agent_session_id' => 'session-state',
        'context' => json_encode(['linear_issue_id' => 'uuid-issue']),
    ]);

    app(LinearNotificationDriver::class)
        ->send($task, NotificationType::Result, 'Done');

    Http::assertSent(function ($request): bool {
        $data = $request->data();
        $vars = $data['variables'] ?? [];

        return str_contains($data['query'] ?? '', 'issueUpdate')
            && ($vars['issueId'] ?? null) === 'uuid-issue'
            && ($vars['stateId'] ?? null) === 'done-state-uuid';
    });
});

it('LinearNotificationDriver is a no-op when no OAuth connection exists', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'session-no-conn',
    ]);

    app(LinearNotificationDriver::class)
        ->send($task, NotificationType::Progress, 'should not be sent');

    Http::assertNothingSent();
});

it('LinearNotificationDriver is a no-op when the task has no linear_agent_session_id', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => null,
    ]);

    app(LinearNotificationDriver::class)
        ->send($task, NotificationType::Progress, 'should not be sent');

    Http::assertNothingSent();
});

it('postAgentActivity posts a freeform activity without needing a task', function () {
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    LinearOauthConnection::factory()->create();

    app(LinearNotificationDriver::class)
        ->postAgentActivity('session-ad-hoc', type: 'error', body: 'Not supported here.');

    Http::assertSent(function ($request): bool {
        $vars = $request->data()['variables'] ?? [];

        return ($vars['input']['agentSessionId'] ?? null) === 'session-ad-hoc'
            && ($vars['input']['content']['type'] ?? null) === 'error'
            && ($vars['input']['content']['body'] ?? null) === 'Not supported here.';
    });
});
