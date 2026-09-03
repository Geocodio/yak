<?php

use App\Models\User;

test('the app shell renders the sidebar nav and the command palette opens', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks');

    $page->assertNoJavaScriptErrors();

    foreach ([
        'Tasks',
        'Repositories',
        'Deployments',
        'PR Reviews',
        'Prompts',
        'Costs',
        'Skills',
        'Health',
        'Settings',
    ] as $label) {
        $page->assertSee($label);
    }

    // Channels lives under Settings, so it is deliberately absent from the
    // sidebar nav -- but the command palette must still offer it. Scoped to
    // the nav because unrelated page copy mentions channels.
    $page->assertMissing('aside nav a[href="/channels"]');

    $page->keys('[data-testid="app-shell"]', ['Meta+k']);

    $page->assertVisible('[data-testid="app-palette"]');

    // The palette lists every destination, so Channels is still reachable
    // from anywhere despite leaving the sidebar.
    $page->assertVisible('[data-testid="palette-item-Channels"]');

    $page->assertNoJavaScriptErrors();
});
