<?php

use App\Livewire\Settings\LinearConnection;
use App\Models\LinearOauthConnection;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_client_secret', 'csecret');
    config()->set('yak.channels.linear.oauth_redirect_uri', 'http://localhost/auth/linear/callback');

    $this->actingAs(User::factory()->create());
});

it('shows the started-state toggle when connected, reflecting the stored value', function () {
    LinearOauthConnection::factory()->create(['move_issues_to_started_state' => true]);

    Livewire::test(LinearConnection::class)
        ->assertSee('Move issues to In Progress')
        ->assertSet('moveIssuesToStartedState', true);
});

it('persists turning the toggle off', function () {
    $connection = LinearOauthConnection::factory()->create(['move_issues_to_started_state' => true]);

    Livewire::test(LinearConnection::class)
        ->set('moveIssuesToStartedState', false);

    expect($connection->fresh()->move_issues_to_started_state)->toBeFalse();
});

it('persists turning the toggle back on', function () {
    $connection = LinearOauthConnection::factory()->create(['move_issues_to_started_state' => false]);

    Livewire::test(LinearConnection::class)
        ->assertSet('moveIssuesToStartedState', false)
        ->set('moveIssuesToStartedState', true);

    expect($connection->fresh()->move_issues_to_started_state)->toBeTrue();
});

it('does not show the toggle when Linear is not connected', function () {
    Livewire::test(LinearConnection::class)
        ->assertDontSee('Move issues to In Progress');
});
