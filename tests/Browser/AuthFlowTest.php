<?php

use App\Models\Thread;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('unauthenticated user visiting tasks is redirected to login', function () {
    $this->get(route('tasks'))
        ->assertRedirect(route('login'));
});

test('oauth flow authenticates user and grants access to the thread log', function () {
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

    Thread::factory()->create(['title' => 'Fix the login bug']);

    $page = visit('/threads/log');

    $page->assertSee('Fix the login bug');
});

test('authenticated user can access the thread log in browser', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Thread::factory()->create(['title' => 'Browser auth test task']);

    $page = visit('/threads/log');

    $page->assertSee('Browser auth test task');
});

test('full auth flow: redirect to login then oauth then thread log', function () {
    config(['services.google.allowed_domains' => 'example.com']);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->map([
        'id' => '789',
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    Socialite::fake('google', $socialiteUser);

    $this->get(route('tasks'))->assertRedirect(route('login'));

    $this->get(route('auth.google.callback'));
    $this->assertAuthenticated();

    Thread::factory()->create(['title' => 'Post-login task']);

    $page = visit('/threads/log');

    $page->assertSee('Post-login task');
});
