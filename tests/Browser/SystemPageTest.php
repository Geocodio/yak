<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
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

test('an admin sees the awake sandboxes card with the slot summary', function () {
    $admin = User::factory()->create();
    $other = User::factory()->create(['name' => 'Tessa Teammate']);
    config(['claude-accounts.admin_user_ids' => [$admin->id], 'yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 4]);
    foreach ([$other, $other, $other, $other] as $owner) {
        Thread::factory()->create(['owner_user_id' => $owner->id, 'sandbox_state' => ThreadSandboxState::Running]);
    }
    $this->actingAs($admin);

    visit('/system')
        ->assertSeeIn('[data-testid="awake-sandboxes-summary"]', '4 of 8 slots in use, 4 free')
        ->assertVisible('[data-testid="awake-sandboxes-at-share"]')
        ->assertSeeIn('[data-testid="awake-sandboxes-cap"]', 'Admins only')
        ->assertNoJavaScriptErrors();
});

test('a member does not see the awake sandboxes card', function () {
    $this->actingAs(User::factory()->create());

    visit('/system')->assertMissing('[data-testid="awake-sandboxes"]')->assertNoJavaScriptErrors();
});
