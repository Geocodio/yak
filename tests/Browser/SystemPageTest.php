<?php

use App\Models\User;

test('the system section shows the package pages inside the yak layout', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/system');

    $page->assertNoJavaScriptErrors()
        ->assertVisible('[data-testid="system-nav"]')
        ->assertVisible('[data-testid="system-body"]')
        ->assertVisible('[data-testid="system-nav"] a[href="/system/p/system"][aria-current="page"]');

    $page->click('[data-testid="system-nav"] a[href="/system/p/dashboard"]')
        ->assertPathIs('/system/p/dashboard')
        ->assertVisible('[data-testid="system-nav"] a[href="/system/p/dashboard"][aria-current="page"]')
        ->assertNoJavaScriptErrors();
});
