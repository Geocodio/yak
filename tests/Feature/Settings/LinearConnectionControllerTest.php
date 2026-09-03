<?php

use App\Models\LinearOauthConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_client_secret', 'csecret');
    config()->set('yak.channels.linear.oauth_redirect_uri', 'http://localhost/auth/linear/callback');

    $this->actingAs(User::factory()->create());
});

test('shows the started-state toggle when connected, reflecting the stored value', function () {
    LinearOauthConnection::factory()->create(['move_issues_to_started_state' => true]);

    $this->get(route('settings.linear'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Linear')
            ->where('linear.isConnected', true)
            ->where('linear.moveIssuesToStartedState', true)
            ->etc());
});

test('persists turning the toggle off', function () {
    $connection = LinearOauthConnection::factory()->create(['move_issues_to_started_state' => true]);

    $this->patch(route('settings.linear.update'), ['moveIssuesToStartedState' => false])
        ->assertRedirect();

    expect($connection->fresh()->move_issues_to_started_state)->toBeFalse();
});

test('persists turning the toggle back on', function () {
    $connection = LinearOauthConnection::factory()->create(['move_issues_to_started_state' => false]);

    $this->patch(route('settings.linear.update'), ['moveIssuesToStartedState' => true])
        ->assertRedirect();

    expect($connection->fresh()->move_issues_to_started_state)->toBeTrue();
});

test('does not report a connection when Linear is not connected', function () {
    $this->get(route('settings.linear'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Linear')
            ->where('linear.isConnected', false)
            ->etc());
});

test('reports oauth as not configured when the client id is missing', function () {
    config()->set('yak.channels.linear.oauth_client_id', null);

    $this->get(route('settings.linear'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Linear')
            ->where('linear.oauthConfigured', false)
            ->etc());
});

test('exposes a human-readable expiry and disconnected-ago alongside the ISO values', function () {
    $connection = LinearOauthConnection::factory()->disconnected()->create(['expires_at' => now()->addDay()]);

    $this->get(route('settings.linear'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Linear')
            ->where('linear.expiresAt', $connection->expires_at->toIso8601String())
            ->where('linear.expiresIn', $connection->expires_at->diffForHumans())
            ->where('linear.disconnectedAt', $connection->disconnected_at->toIso8601String())
            ->where('linear.disconnectedAgo', $connection->disconnected_at->diffForHumans())
            ->etc());
});

test('disconnects the linear connection', function () {
    $connection = LinearOauthConnection::factory()->create();

    $this->delete(route('settings.linear.disconnect'))
        ->assertRedirect(route('settings.linear'));

    expect(LinearOauthConnection::query()->find($connection->id))->toBeNull();
});
