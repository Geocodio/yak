<?php

use App\Models\LinearOauthConnection;
use App\Services\HealthCheck\Channel\LinearChannelCheck;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'yak.channels.linear.oauth_client_id' => 'client-id',
        'yak.channels.linear.oauth_client_secret' => 'secret',
        'yak.channels.linear.oauth_redirect_uri' => 'https://yak.test/auth/linear/callback',
    ]);
});

it('returns NotConnected with a Connect action when no oauth connection exists', function () {
    $result = app(LinearChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::NotConnected);
    expect($result->detail)->toContain('not');
    expect($result->action?->label)->toBe('Connect Linear');
    expect($result->action?->url)->toBe(route('auth.linear.redirect'));
});

it('returns NotConnected when the latest connection is disconnected', function () {
    LinearOauthConnection::factory()->create([
        'disconnected_at' => now()->subMinute(),
    ]);

    $result = app(LinearChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::NotConnected);
    expect($result->action?->label)->toBe('Connect Linear');
});

it('returns Ok when viewer lookup succeeds', function () {
    LinearOauthConnection::factory()->create([
        'access_token' => 'lin_api_test',
        'workspace_name' => 'Acme',
        'disconnected_at' => null,
        'expires_at' => now()->addDay(),
    ]);

    Http::fake([
        'api.linear.app/graphql' => Http::response([
            'data' => ['viewer' => ['id' => 'u-123', 'name' => 'Yak Bot']],
        ]),
    ]);

    $result = app(LinearChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('Acme');
});

it('returns Error when viewer lookup fails with 401', function () {
    LinearOauthConnection::factory()->create([
        'access_token' => 'lin_api_bad',
        'disconnected_at' => null,
        'expires_at' => now()->addDay(),
    ]);

    Http::fake([
        'api.linear.app/graphql' => Http::response('Unauthorized', 401),
    ]);

    $result = app(LinearChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->action?->label)->toBe('Reconnect Linear');
});
