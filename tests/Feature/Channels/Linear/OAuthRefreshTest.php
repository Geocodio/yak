<?php

use App\Channels\Linear\OAuthService as LinearOAuthService;
use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_client_secret', 'csecret');
});

test('freshAccessToken returns current token when not near expiry', function () {
    Http::fake();

    $connection = LinearOauthConnection::factory()->create([
        'access_token' => 'current-token',
        'expires_at' => now()->addHour(),
    ]);

    $token = $connection->freshAccessToken(app(LinearOAuthService::class));

    expect($token)->toBe('current-token');
    Http::assertNothingSent();
});

test('freshAccessToken refreshes when within skew window', function () {
    Http::fake([
        'api.linear.app/oauth/token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 86400,
        ]),
    ]);

    $connection = LinearOauthConnection::factory()->create([
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'expires_at' => now()->addSeconds(30),
    ]);

    $token = $connection->freshAccessToken(app(LinearOAuthService::class));

    expect($token)->toBe('new-access');
    $connection->refresh();
    expect((string) $connection->access_token)->toBe('new-access');
    expect((string) $connection->refresh_token)->toBe('new-refresh');
    expect($connection->expires_at->greaterThan(now()->addHours(23)))->toBeTrue();
});

test('freshAccessToken keeps old refresh token when Linear does not rotate it', function () {
    Http::fake([
        'api.linear.app/oauth/token' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 86400,
        ]),
    ]);

    $connection = LinearOauthConnection::factory()->create([
        'refresh_token' => 'keep-me',
        'expires_at' => now()->subMinute(),
    ]);

    $connection->freshAccessToken(app(LinearOAuthService::class));
    $connection->refresh();

    expect((string) $connection->refresh_token)->toBe('keep-me');
});

test('refresh marks connection disconnected and throws on invalid_grant', function () {
    Http::fake([
        'api.linear.app/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $connection = LinearOauthConnection::factory()->create([
        'refresh_token' => 'revoked',
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => $connection->freshAccessToken(app(LinearOAuthService::class)))
        ->toThrow(LinearOAuthRefreshFailedException::class);

    $connection->refresh();
    expect($connection->disconnected_at)->not->toBeNull();
});

test('refresh marks disconnected when no refresh token is stored', function () {
    $connection = LinearOauthConnection::factory()->create([
        'refresh_token' => null,
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(LinearOAuthService::class)->refresh($connection))
        ->toThrow(LinearOAuthRefreshFailedException::class);

    $connection->refresh();
    expect($connection->disconnected_at)->not->toBeNull();
});
