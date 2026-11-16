<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);
});

it('forbids members on both routes', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($member);

    $this->get('/settings/people')->assertForbidden();
    $this->patch("/settings/people/{$other->id}", ['role' => 'admin'])->assertForbidden();
    expect($other->fresh()->is_admin)->toBeFalse();
});

it('lists every user ordered by name for an admin', function () {
    $admin = User::factory()->admin()->create(['name' => 'Mia']);
    User::factory()->create(['name' => 'Zed']);
    User::factory()->create(['name' => 'Abe']);
    $this->actingAs($admin);

    $this->get('/settings/people')->assertInertia(fn (Assert $page) => $page
        ->component('Settings/People')
        ->has('people', 3)
        ->where('people.0.name', 'Abe')
        ->where('people.1.isYou', true)
        ->where('people.1.lockReason', 'You cannot change your own role')
        ->where('people.2.name', 'Zed'));
});

it('promotes and demotes another user', function () {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create(['name' => 'Abe']);

    $this->patch("/settings/people/{$target->id}", ['role' => 'admin'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Made Abe an admin.');
    expect($target->fresh()->is_admin)->toBeTrue();

    $this->patch("/settings/people/{$target->id}", ['role' => 'member'])
        ->assertSessionHas('success', 'Abe is now a member.');
    expect($target->fresh()->is_admin)->toBeFalse();
});

it('rejects an invalid role', function () {
    $this->actingAs(User::factory()->admin()->create());
    $target = User::factory()->create();

    $this->patch("/settings/people/{$target->id}", ['role' => 'owner'])->assertSessionHasErrors('role');
});

it('does not let an admin change their own role', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->patch("/settings/people/{$admin->id}", ['role' => 'member'])
        ->assertSessionHasErrors(['role' => 'You cannot change your own role.']);
    expect($admin->fresh()->is_admin)->toBeTrue();
});

it('does not change a config-listed admin', function () {
    $listed = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$listed->id]]);
    $this->actingAs(User::factory()->admin()->create());

    $this->patch("/settings/people/{$listed->id}", ['role' => 'member'])
        ->assertSessionHasErrors(['role' => 'This role is set in the server config.']);
});
