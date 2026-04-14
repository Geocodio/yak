<?php

use App\Models\LinearOauthConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_client_secret', 'csecret');
    config()->set('yak.channels.linear.oauth_redirect_uri', 'http://localhost/auth/linear/callback');
    config()->set('yak.channels.linear.oauth_scopes', 'read,write,issues:create,comments:create');
});

test('unauthenticated user cannot start the Linear OAuth flow', function () {
    $this->get('/auth/linear')->assertRedirect('/auth/google');
});

test('redirect route sends the user to Linear with correct params', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/auth/linear');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://linear.app/oauth/authorize?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'client_id' => 'cid',
        'redirect_uri' => 'http://localhost/auth/linear/callback',
        'response_type' => 'code',
        'actor' => 'app',
        'prompt' => 'consent',
    ])
        ->and($query['scope'])->toBe('read write issues:create comments:create')
        ->and($query)->toHaveKey('state');
});

test('callback with mismatched state is rejected', function () {
    $this->actingAs(User::factory()->create());

    session()->put('linear_oauth_state', 'expected');

    $this->get('/auth/linear/callback?code=abc&state=tampered')->assertForbidden();
});

test('callback persists a connection on success', function () {
    Http::fake([
        'api.linear.app/oauth/token' => Http::response([
            'access_token' => 'lin_access_abc',
            'refresh_token' => 'lin_refresh_abc',
            'expires_in' => 86400,
            'scope' => 'read write issues:create comments:create',
            'token_type' => 'Bearer',
        ]),
        'api.linear.app/graphql' => Http::response([
            'data' => [
                'viewer' => ['id' => 'user-123', 'name' => 'Installer'],
                'organization' => ['id' => 'org-456', 'name' => 'Geocodio', 'urlKey' => 'geocodio'],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    session()->put('linear_oauth_state', 'good-state');

    $this->get('/auth/linear/callback?code=xyz&state=good-state')
        ->assertRedirect(route('settings.linear'));

    $connection = LinearOauthConnection::query()->first();
    expect($connection)->not->toBeNull()
        ->and($connection->workspace_id)->toBe('org-456')
        ->and($connection->workspace_name)->toBe('Geocodio')
        ->and((string) $connection->access_token)->toBe('lin_access_abc')
        ->and((string) $connection->refresh_token)->toBe('lin_refresh_abc')
        ->and($connection->created_by_user_id)->toBe($user->id)
        ->and($connection->actor)->toBe('app');
});

test('callback flashes error when Linear returns an error response', function () {
    $this->actingAs(User::factory()->create());
    session()->put('linear_oauth_state', 'good');

    $this->get('/auth/linear/callback?state=good&error=access_denied')
        ->assertRedirect(route('settings.linear'));

    expect(LinearOauthConnection::count())->toBe(0);
});
