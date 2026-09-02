<?php

use App\Models\User;

test('the app shell renders the sidebar nav and the command palette opens', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/inertia-boot');

    $page->assertNoJavaScriptErrors();

    foreach ([
        'Tasks',
        'Costs',
        'Repositories',
        'Deployments',
        'PR Reviews',
        'Channels',
        'Prompts',
        'Skills',
        'Health',
        'Settings',
    ] as $label) {
        $page->assertSee($label);
    }

    $page->keys('[data-testid="app-shell"]', ['Meta+k']);

    $page->assertVisible('[data-testid="app-palette"]');

    $page->assertNoJavaScriptErrors();
});
