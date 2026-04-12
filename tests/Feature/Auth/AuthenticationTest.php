<?php

use App\Models\User;

test('unauthenticated users are redirected to login', function () {
    $response = $this->get(route('tasks'));

    $response->assertRedirect(route('login'));
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
