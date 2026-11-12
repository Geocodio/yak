<?php

use App\Models\User;

test('an admin changes another person\'s role and sees the toast', function () {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Ada Admin']));
    $target = User::factory()->create(['name' => 'Bo Member']);

    visit('/settings/people')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-testid="person-row"]')
        ->click('[aria-label="Role for Bo Member"]')
        ->click('[role="option"]:has-text("Admin")')
        ->assertSee('Made Bo Member an admin.')
        ->assertNoJavaScriptErrors();

    expect($target->fresh()->is_admin)->toBeTrue();
});

test('a member does not see the People nav item', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/devices')
        ->assertNoJavaScriptErrors()
        ->assertSee('SSH keys')
        ->assertDontSee('People');
});
