<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('inertia boots with shared props', function () {
    $this->actingAs(User::factory()->create(['name' => 'Ada Lovelace']));

    $this->get('/inertia-boot')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Placeholder')
            ->where('label', 'boot')
            ->where('auth.user.name', 'Ada Lovelace')
            ->has('nav.activeTaskCount')
            ->has('docs.baseUrl'));
});
