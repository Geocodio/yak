<?php

use App\Models\User;

test('guests see the login page at the homepage', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Continue with Google');
});

test('authenticated users are redirected from homepage to tasks', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertRedirect(route('tasks'));
});
