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
