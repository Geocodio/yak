<?php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['has_seen_setup_card_at' => now()]));
});

test('it requires authentication', function () {
    auth()->logout();

    $this->get(route('tasks'))->assertRedirect(route('login'));
});
