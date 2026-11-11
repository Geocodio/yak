<?php

use App\Enums\ThreadOwnerSide;
use App\Models\Device;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\DeviceTokens;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->partial = function () {
        $this->actingAs($this->user);
        $version = (string) $this->get(route('settings.devices'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

        return $this->get(route('settings.devices'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Settings/Devices',
            'X-Inertia-Partial-Data' => 'devices',
        ]);
    };
});

it('lists only my active devices, newest first', function () {
    $old = Device::factory()->forUser($this->user)->create(['created_at' => now()->subDay()]);
    $new = Device::factory()->forUser($this->user)->create();
    Device::factory()->forUser($this->user)->revoked()->create();
    Device::factory()->create();

    $this->actingAs($this->user)->get(route('settings.devices'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Devices')
            ->has('installCommand')->has('loginCommand')->missing('devices'));

    ($this->partial)()
        ->assertJsonCount(2, 'props.devices')->assertJsonPath('props.devices.0.id', $new->id)->assertJsonPath('props.devices.1.id', $old->id);
});

it('flags a device stale at 90 days', function () {
    Device::factory()->forUser($this->user)->stale()->create();
    Device::factory()->forUser($this->user)->create(['last_seen_at' => now()]);

    $devices = ($this->partial)()->json('props.devices');

    expect(collect($devices)->where('is_stale', true))->toHaveCount(1)
        ->and(collect($devices)->firstWhere('is_stale', true)['stale_days'])->toBeGreaterThanOrEqual(90);
});

it('hides last_teleport when the thread is private to someone else', function () {
    $thread = Thread::factory()->private()->create();
    $visible = Thread::factory()->create(['title' => 'Visible one']);
    Device::factory()->forUser($this->user)->create(['last_teleport_thread_id' => $thread->id, 'last_teleport_at' => now(), 'created_at' => now()->subDay()]);
    Device::factory()->forUser($this->user)->create(['last_teleport_thread_id' => $visible->id, 'last_teleport_at' => now()]);

    ($this->partial)()
        ->assertJsonPath('props.devices.0.last_teleport.title', 'Visible one')->assertJsonPath('props.devices.1.last_teleport', null);
});

it('names the threads the device owns', function () {
    $device = Device::factory()->forUser($this->user)->create();
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['title' => 'On my laptop']);

    ($this->partial)()
        ->assertJsonPath('props.devices.0.owned_threads', [['id' => $thread->id, 'title' => 'On my laptop']]);
});

it('revoke stops the token and reports device_revoked on /me', function () {
    $token = app(DeviceTokens::class)->generate();
    $device = Device::factory()->forUser($this->user)->create(['token_hash' => app(DeviceTokens::class)->hash($token)]);

    $this->actingAs($this->user)->delete(route('settings.devices.destroy', $device))
        ->assertRedirect()->assertSessionHas('success', "Revoked {$device->name}.");

    expect($device->fresh()->isRevoked())->toBeTrue();
    $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Yak-Protocol' => '1'])->getJson('/api/cli/v1/me')
        ->assertJson(['error' => 'device_revoked']);
});

it('revoke hands owned threads back to yak and keeps their title', function () {
    $device = Device::factory()->forUser($this->user)->create();
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['title' => 'Keep me']);

    $this->actingAs($this->user)->delete(route('settings.devices.destroy', $device));

    $thread->refresh();
    expect($thread->owner_side)->toBe(ThreadOwnerSide::Yak)->and($thread->owner_device_id)->toBeNull()->and($thread->title)->toBe('Keep me');
});

it("404s revoking another user's device and changes nothing", function () {
    $device = Device::factory()->create();

    $this->actingAs($this->user)->delete(route('settings.devices.destroy', $device))->assertNotFound();

    expect($device->fresh()->isRevoked())->toBeFalse();
});

it('404s revoking an already revoked device', function () {
    $device = Device::factory()->forUser($this->user)->revoked()->create();

    $this->actingAs($this->user)->delete(route('settings.devices.destroy', $device))->assertNotFound();
});
