<?php

use App\Models\User;

test('a new user sees the setup checklist and no start form until setup is done', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->assertVisible('[data-testid="welcome-checklist"]')
        ->assertVisible('[data-testid="welcome-start-locked"]')
        ->assertMissing('[data-testid="start-thread-no-accounts"]')
        ->assertNoJavaScriptErrors();
});
