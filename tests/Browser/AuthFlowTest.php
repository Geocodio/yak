<?php

use App\Models\User;
use App\Models\YakTask;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('unauthenticated user visiting tasks is redirected to login', function () {
    $this->get(route('tasks'))
        ->assertRedirect(route('login'));
});

test('oauth flow authenticates user and grants access to task list', function () {
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

    YakTask::factory()->success()->create([
        'description' => 'Fix the login bug',
    ]);

    $page = visit('/tasks');

    $page->assertSee('Fix the login bug');
});

test('authenticated user can access task list in browser', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    YakTask::factory()->success()->create([
        'description' => 'Browser auth test task',
    ]);

    $page = visit('/tasks');

    $page->assertSee('Browser auth test task')
        ->assertSee('Status')
        ->assertSee('Description');
});

test('full auth flow: redirect to login then oauth then task list', function () {
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

    YakTask::factory()->success()->create([
        'description' => 'Post-login task',
    ]);

    $page = visit('/tasks');

    $page->assertSee('Post-login task');
});
