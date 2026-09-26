<?php

use App\Models\User;

test('on a desktop the skills header shows its secondary action as a button', function () {
    $this->actingAs(User::factory()->create());

    visit('/skills')
        ->assertVisible('[data-testid="refresh-marketplaces"]')
        ->assertVisible('[data-testid="open-install-from-url"]')
        ->assertMissing('[data-testid="page-header-overflow-menu"]:visible')
        ->assertNoJavaScriptErrors();
});

test('on a phone the skills header folds its secondary action into a menu and keeps search visible', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/skills')->on()->mobile()->assertVisible('[data-testid="open-install-from-url"]');

    $page->assertVisible('[data-testid="open-install-from-url"]')
        ->assertVisible('[data-testid="skills-search"]')
        ->assertMissing('[data-testid="refresh-marketplaces"]:visible')
        ->click('[data-testid="page-header-overflow-menu"]')
        ->assertSee('Refresh marketplaces')
        ->assertNoJavaScriptErrors();

    // Nothing in the header may push the page wider than the phone.
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
