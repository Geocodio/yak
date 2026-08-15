<?php

use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Models\LinearOauthConnection;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('yak.channels.linear', ['webhook_secret' => 'secret']);
});

it('postAction emits an agentActivityCreate mutation with action content type', function (): void {
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    LinearOauthConnection::factory()->create();

    app(LinearNotificationDriver::class)
        ->postAction('session-action-123', 'Opened pull request', 'https://github.com/org/repo/pull/42');

    Http::assertSent(function ($request): bool {
        $vars = $request->data()['variables'] ?? [];
        $content = $vars['input']['content'] ?? [];

        return str_contains($request->url(), 'api.linear.app/graphql')
            && ($vars['input']['agentSessionId'] ?? null) === 'session-action-123'
            && ($content['type'] ?? null) === 'action'
            && ($content['action'] ?? null) === 'Opened pull request'
            && ($content['result'] ?? null) === 'https://github.com/org/repo/pull/42';
    });
});

it('postAction emits an action mutation without a result when result is null', function (): void {
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    LinearOauthConnection::factory()->create();

    app(LinearNotificationDriver::class)
        ->postAction('session-no-result', 'Started analysis');

    Http::assertSent(function ($request): bool {
        $vars = $request->data()['variables'] ?? [];
        $content = $vars['input']['content'] ?? [];

        return str_contains($request->url(), 'api.linear.app/graphql')
            && ($vars['input']['agentSessionId'] ?? null) === 'session-no-result'
            && ($content['type'] ?? null) === 'action'
            && ($content['action'] ?? null) === 'Started analysis'
            && array_key_exists('result', $content)
            && $content['result'] === null;
    });
});

it('postAction is a no-op when there is no active OAuth connection', function (): void {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);

    app(LinearNotificationDriver::class)
        ->postAction('session-no-token', 'Some action');

    Http::assertNothingSent();
});

it('postAction is a no-op when the session id is empty', function (): void {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();

    app(LinearNotificationDriver::class)
        ->postAction('', 'Some action');

    Http::assertNothingSent();
});
