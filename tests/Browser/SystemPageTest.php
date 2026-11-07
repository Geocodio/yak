<?php

use App\Models\User;

test('the system page shows its three sections without errors', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/system');

    $page->assertNoJavaScriptErrors()
        ->assertVisible('[data-testid="system-now"]')
        ->assertVisible('[data-testid="system-history"]')
        ->assertVisible('[data-testid="system-queues"]');
});
