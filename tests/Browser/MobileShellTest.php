<?php

use App\Models\User;

test('the phone shell opens a left drawer from the header toggle and closes it again', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/tasks')->on()->mobile();

    $page->assertVisible('[data-testid="mobile-nav-toggle"]')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->click('[data-testid="mobile-nav-toggle"]')
        ->assertVisible('[data-testid="mobile-nav"]')
        ->assertVisible('[data-testid="mobile-section-switcher"]')
        ->assertVisible('[data-testid="mobile-section-threads"][aria-label="Threads"]')
        ->assertVisible('[data-testid="mobile-section-operations"][aria-label="Operations"]')
        ->assertVisible('[data-testid="mobile-section-insights"][aria-label="Insights"]')
        ->assertVisible('[data-testid="mobile-section-settings"][aria-label="Settings"]')
        ->assertScript('document.querySelector(\'[data-testid="mobile-section-switcher"]\').innerText.trim()', '')
        ->click('[data-testid="mobile-nav-close"]')
        ->assertMissing('[data-testid="mobile-nav"]')
        ->assertNoJavaScriptErrors();
});
