<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']));
});

test('profile page is displayed', function () {
    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Profile')
            ->where('profile.name', 'Test User')
            ->where('profile.email', 'test@example.com')
            ->etc());
});

test('profile information can be updated', function () {
    $user = User::query()->first();

    $this->patch(route('profile.update'), [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ])->assertRedirect();

    $user->refresh();

    expect($user->name)->toBe('New Name');
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::query()->first();

    $this->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => $user->email,
    ])->assertRedirect();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('profile update validates required fields', function () {
    $this->patch(route('profile.update'), ['name' => '', 'email' => ''])
        ->assertSessionHasErrors(['name', 'email']);
});

test('profile page exposes the direct messages flag', function () {
    $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('profile.directMessagesEnabled', true)->etc());
});

test('direct messages can be turned off and on', function () {
    $user = User::query()->first();

    $this->patch(route('profile.direct-messages.update'), ['directMessagesEnabled' => false])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('success', 'Slack direct messages turned off.');
    expect($user->refresh()->direct_messages_enabled)->toBeFalse();

    $this->patch(route('profile.direct-messages.update'), ['directMessagesEnabled' => true])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('success', 'Slack direct messages turned on.');
    expect($user->refresh()->direct_messages_enabled)->toBeTrue();
});

test('direct messages update validates the value', function (array $payload) {
    $this->patch(route('profile.direct-messages.update'), $payload)
        ->assertSessionHasErrors('directMessagesEnabled');
})->with([[[]], [['directMessagesEnabled' => 'maybe']]]);

test('guests cannot update direct messages', function () {
    auth()->logout();

    $this->patch(route('profile.direct-messages.update'), ['directMessagesEnabled' => false])
        ->assertRedirect(route('login'));
});
