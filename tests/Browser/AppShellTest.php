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
    // sidebar -- but the command palette must still offer it.
    $page->assertDontSee('Channels');

    $page->keys('[data-testid="app-shell"]', ['Meta+k']);

    $page->assertVisible('[data-testid="app-palette"]');

    $page->type('[data-testid="app-palette"] input', 'Channels');

    $page->assertSee('Channels');

    $page->assertNoJavaScriptErrors();
});
