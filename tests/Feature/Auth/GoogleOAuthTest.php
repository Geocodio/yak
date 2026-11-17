<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('unauthenticated users are redirected to the login page', function () {
    $this->get(route('threads.log'))
        ->assertRedirect(route('login'));
});

test('login route renders the sign-in page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Google');
});

test('auth.google route redirects to google', function () {
    Socialite::fake('google');

    $this->get(route('auth.google'))
        ->assertRedirect();
});

test('oauth callback creates user and authenticates', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('home'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'jane@example.com',
        'name' => 'Jane Doe',
    ]);
});

test('oauth callback updates existing user', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $user = User::factory()->create([
        'email' => 'jane@example.com',
        'name' => 'Old Name',
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('home'));

    $this->assertAuthenticated();
    expect($user->fresh()->name)->toBe('Jane Doe');
    expect(User::count())->toBe(1);
});

test('oauth callback rejects users from disallowed domains', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Hacker',
        'email' => 'hacker@evil.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'hacker@evil.com']);
});

test('oauth callback supports multiple allowed domains', function () {
    config(['services.google.allowed_domains' => 'example.com, acme.org']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '789',
        'name' => 'Acme User',
        'email' => 'user@acme.org',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('home'));

    $this->assertAuthenticated();
});

test('oauth callback aborts when allowed domains not configured', function () {
    config(['services.google.allowed_domains' => null]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123',
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertStatus(500);
});

test('oauth callback aborts when allowed domains is empty string', function () {
    config(['services.google.allowed_domains' => '']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123',
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))
        ->assertStatus(500);
});

test('session persists across page navigation after oauth login', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'));
    $this->assertAuthenticated();

    $this->get(route('threads.log'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticated();
});

test('google sign-in stores the profile picture', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'avatar' => 'https://lh3.googleusercontent.com/a/picture',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))->assertRedirect(route('home'));

    expect(User::firstWhere('email', 'jane@example.com')->avatar_url)
        ->toBe('https://lh3.googleusercontent.com/a/picture');
});

test('google sign-in clears the profile picture when Google sends none', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $user = User::factory()->create([
        'email' => 'jane@example.com',
        'avatar_url' => 'https://lh3.googleusercontent.com/a/old',
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '123456',
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('auth.google.callback'))->assertRedirect(route('home'));

    expect($user->fresh()->avatar_url)->toBeNull();
});
