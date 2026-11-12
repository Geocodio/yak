<?php

use App\Models\Device;
use App\Models\Thread;
use App\Models\User;

test('a person revokes a computer that owns a thread', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $device = Device::factory()->forUser($user)->create(['name' => 'work-mac']);
    Thread::factory()->ownedByDevice($device->id)->create(['title' => 'Fix the billing export']);

    visit('/settings/devices')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-testid="device-row"]')
        ->assertSee('Fix the billing export')
        ->click('[data-testid="device-revoke"]')
        ->assertSee('returns to Yak as it was when it left')
        ->click('[data-testid="device-revoke-confirm"]')
        ->assertSee('Revoked work-mac. Its token stopped working right away.')
        ->assertPresent('[data-testid="no-devices"]')
        ->assertNoJavaScriptErrors();

    expect($device->fresh()->isRevoked())->toBeTrue();
});

test('a person with a paired computer can still add another', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Device::factory()->forUser($user)->create();

    visit('/settings/devices')
        ->assertPresent('[data-testid="add-computer"]')
        ->assertSee('/install | sh')
        ->assertNoJavaScriptErrors();
});

test('a stale computer shows its hint', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Device::factory()->forUser($user)->stale()->create();

    visit('/settings/devices')
        ->assertSee('Not used in 91 days. Revoke it if this computer is gone.')
        ->assertPresent('[data-testid="device-stale-hint"]')
        ->assertNoJavaScriptErrors();
});

test('a person with no computers sees the install command and the waiting row', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/devices')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-testid="teleport-install-command"]')
        ->assertSee('/install | sh')
        ->assertPresent('[data-testid="teleport-waiting"]')
        ->assertSee('Waiting for your computer');
});
