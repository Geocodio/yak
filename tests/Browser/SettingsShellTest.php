<?php

use App\Models\User;

test('settings pages render inside the app shell with a Settings / Page breadcrumb', function (string $path, string $crumb) {
    $this->actingAs(User::factory()->create());

    $page = visit($path);

    $page->assertVisible('[data-testid="rail-settings"]')
        ->assertVisible('[data-testid="section-panel-settings"]')
        ->assertSee('Settings')
        ->assertSeeIn('[data-testid="page-header-crumbs"]', $crumb)
        ->assertDontSee('Back to Yak')
        ->assertNoJavaScriptErrors();
})->with([
    'profile' => ['/settings/profile', 'Profile'],
    'claude accounts' => ['/settings/claude-accounts', 'Claude accounts'],
]);
