<?php

use App\Models\ClaudeAccount;
use App\Models\Device;
use App\Models\User;

test('a person with nothing set up sees the bar, dismisses it, and it stays dismissed', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/settings/devices');

    $page->assertNoJavaScriptErrors()
        ->assertAttribute('[data-testid="setup-bar"]', 'data-state', 'both')
        ->click('[data-testid="setup-bar-dismiss"]')
        ->assertMissing('[data-testid="setup-bar"]')
        ->refresh()
        ->assertMissing('[data-testid="setup-bar"]');
});

test('the bar opens the connect dialog and the Teleport dialog in place', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/devices')
        ->click('[data-testid="setup-bar-connect-claude"]')
        ->assertPresent('[data-testid="account-secret"]')
        ->click('[data-testid="account-cancel"]')
        ->click('[data-testid="setup-bar-set-up-teleport"]')
        ->assertPresent('[data-testid="teleport-install-command"]')
        ->assertNoJavaScriptErrors();
});

test('an admin without a shared Yak account sees a bar that cannot be dismissed', function () {
    $this->actingAs(User::factory()->admin()->create());

    visit('/settings/devices')
        ->assertAttribute('[data-testid="setup-bar"]', 'data-state', 'yak-account')
        ->assertMissing('[data-testid="setup-bar-dismiss"]')
        ->assertSee('Yak has no shared Claude account');
});

test('nothing is shown once everything is set up', function () {
    $user = User::factory()->create();
    ClaudeAccount::factory()->for($user, 'owner')->create();
    Device::factory()->forUser($user)->create();
    $this->actingAs($user);

    visit('/settings/devices')->assertMissing('[data-testid="setup-bar"]');
});

test('the bar stays off the welcome page, which has its own checklist', function () {
    $this->actingAs(User::factory()->create());

    visit('/')
        ->assertPresent('[data-testid="welcome-page"]')
        ->assertMissing('[data-testid="setup-bar"]')
        ->assertNoJavaScriptErrors();
});
