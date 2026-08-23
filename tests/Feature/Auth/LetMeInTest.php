<?php

use App\Models\User;

test('letmein returns 404 outside the local environment', function () {
    $this->get('/letmein')->assertNotFound();

    $this->assertGuest();
});

test('letmein logs in the first user in the local environment', function () {
    $this->app['env'] = 'local';

    $user = User::factory()->create();

    $this->get('/letmein')->assertRedirect(route('tasks'));

    $this->assertAuthenticatedAs($user);
});

test('letmein creates a user when none exist in the local environment', function () {
    $this->app['env'] = 'local';

    $this->get('/letmein')->assertRedirect(route('tasks'));

    $this->assertAuthenticated();
    expect(User::count())->toBe(1);
});
