<?php

use App\Models\User;

beforeEach(fn () => $this->withoutVite());

test('configuration pages moved under Settings still render', function (string $routeName) {
    $user = User::factory()->create(['has_seen_setup_card_at' => now()]);

    $this->actingAs($user)->followingRedirects()->get(route($routeName))->assertOk();
})->with(['prompts', 'skills', 'mcp', 'system', 'channels', 'profile.edit', 'settings.linear', 'settings.video']);
