<?php

use App\Models\User;

test('the rail switches between the thread list and the section drawers', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/repos');

    $page->assertVisible('[data-testid="icon-rail"]')
        ->assertVisible('[data-testid="rail-threads"]')
        ->assertVisible('[data-testid="rail-operations"]')
        ->assertVisible('[data-testid="rail-insights"]')
        ->assertVisible('[data-testid="rail-settings"]')
        ->assertVisible('[data-testid="section-panel-operations"]')
        ->click('[data-testid="rail-insights"]')
        ->assertVisible('[data-testid="section-panel-insights"]')
        ->assertSee('Costs')
        ->click('[data-testid="rail-settings"]')
        ->assertVisible('[data-testid="section-panel-settings"]')
        ->assertSee('Prompts')
        ->assertSee('Skills')
        ->click('[data-testid="rail-threads"]')
        ->assertVisible('[data-testid="thread-list-panel"]')
        ->assertNoJavaScriptErrors();
});

test('the command palette still offers Channels', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks');

    $page->keys('[data-testid="app-shell"]', ['Meta+k'])
        ->assertVisible('[data-testid="app-palette"]')
        ->assertVisible('[data-testid="palette-item-Channels"]')
        ->assertNoJavaScriptErrors();
});

test('the phone sheet holds the section switcher and the user row', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks')->on()->mobile();

    $page->click('[data-testid="mobile-nav-trigger"]')
        ->assertVisible('[data-testid="mobile-section-switcher"]')
        ->assertVisible('[data-testid="thread-list-panel"]')
        ->click('[data-testid="mobile-section-operations"]')
        ->assertVisible('[data-testid="section-panel-operations"]')
        ->assertVisible('[data-testid="mobile-nav-user"]')
        ->assertNoJavaScriptErrors();
});
