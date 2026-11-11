<?php

use App\Models\LinearOauthConnection;
use App\Providers\ChannelServiceProvider;

const TEST_YAK_ACTOR_ID = 'yak-actor-id';
const TEST_WORKSPACE_ID = 'workspace-uuid-001';

function signLinearPayload(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

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
    $issue = [
        'id' => $overrides['issueId'] ?? 'issue-uuid-001',
        'identifier' => $overrides['identifier'] ?? 'ENG-42',
        'title' => $overrides['title'] ?? 'Fix the broken auth flow',
        'description' => $overrides['description'] ?? 'Intermittent 500 on login.',
        'url' => $overrides['url'] ?? 'https://linear.app/team/issue/ENG-42',
    ];

    if (array_key_exists('labels', $overrides)) {
        $issue['labels'] = $overrides['labels'];
    }

    $agentSession = [
        'id' => $overrides['sessionId'] ?? 'session-uuid-001',
        'issue' => $issue,
        'promptContext' => $overrides['promptContext'] ?? '',
    ];

    if (array_key_exists('creatorName', $overrides)) {
        $agentSession['creator'] = ['id' => 'user-uuid-001', 'name' => $overrides['creatorName']];
    }

    return (string) json_encode(array_filter([
        'type' => 'AgentSessionEvent',
        'action' => 'created',
        'organizationId' => $overrides['workspaceId'] ?? TEST_WORKSPACE_ID,
        'actor' => $overrides['actor'] ?? null,
        'agentSession' => $agentSession,
    ]));
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
