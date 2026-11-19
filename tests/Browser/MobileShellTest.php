<?php

use App\Models\User;

test('the phone shell opens a left drawer from the header toggle and closes it again', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/threads/log')->on()->mobile();

    $page->assertVisible('[data-testid="mobile-nav-toggle"]')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->click('[data-testid="mobile-nav-toggle"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->assertVisible('[data-testid="mobile-section-switcher"]')
        ->assertSeeIn('[data-testid="mobile-section-threads"]', 'Threads')
        ->assertSeeIn('[data-testid="mobile-section-operations"]', 'Operations')
        ->assertSeeIn('[data-testid="mobile-section-insights"]', 'Insights')
        ->assertSeeIn('[data-testid="mobile-section-settings"]', 'Settings')
        ->click('[data-testid="mobile-nav-close"]')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->assertNoJavaScriptErrors();
});

test('the navigation drawer search button opens the palette at phone width', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/threads/new')->on()->mobile();

    $page->click('[data-testid="mobile-nav-toggle"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->click('[data-testid="mobile-nav"] [data-testid="thread-search-button"]')
        ->assertVisible('[data-testid="app-palette"]')
        ->assertNoJavaScriptErrors();
});
