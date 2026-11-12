<?php

use App\Models\User;

test('guests see the login page at the homepage', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('Sign in');
});

test('authenticated users with no threads see the welcome page at the homepage', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Home/Welcome'));
});
