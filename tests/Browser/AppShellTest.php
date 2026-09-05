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
        'MCP servers',
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

test('the sidebar shows the Yak brand lockup', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks');

    $page->assertVisible('[data-testid="app-brand"]')
        ->assertMissing('[data-testid="mobile-nav-trigger"]')
        ->assertNoJavaScriptErrors();
});

test('on a phone the sidebar folds into a top bar and a navigation drawer', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks')->on()->mobile();

    // The desktop sidebar stays in the DOM but is hidden below `lg`; the top
    // bar carries the brand and the menu button instead.
    $page->assertMissing('[data-testid="sidebar"]')
        ->assertVisible('[data-testid="mobile-top-bar"]')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->click('[data-testid="mobile-nav-trigger"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->assertVisible('[data-testid="mobile-nav"] a[href="/repos"]')
        ->click('[data-testid="mobile-nav"] a[href="/repos"]')
        ->assertPathIs('/repos')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->assertNoJavaScriptErrors();
});
