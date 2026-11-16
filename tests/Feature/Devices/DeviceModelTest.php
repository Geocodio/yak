<?php

use App\Models\Device;
use App\Models\DeviceAuthorization;
use App\Models\Thread;

it('flags a device stale after 90 days', function () {
    $device = Device::factory()->create(['last_seen_at' => now()]);

    $this->travel(89)->days();
    expect($device->fresh()->isStale())->toBeFalse();

    $this->travel(2)->days();
    expect($device->fresh()->isStale())->toBeTrue();
});

it('falls back to created_at when last_seen_at is null', function () {
    $device = Device::factory()->create(['last_seen_at' => null]);

    $this->travel(91)->days();

    expect($device->fresh()->isStale())->toBeTrue();
});

it('excludes revoked devices from the active scope', function () {
    $active = Device::factory()->create();
    Device::factory()->revoked()->create();

    expect(Device::active()->pluck('id')->all())->toBe([$active->id]);
});

it('nulls threads.owner_device_id when a device is deleted', function () {
    $device = Device::factory()->create();
    $thread = Thread::factory()->create(['owner_device_id' => $device->id]);

    expect($device->ownedThreads()->count())->toBe(1);

    $device->delete();

    expect($thread->fresh()->owner_device_id)->toBeNull();
});

it('selects only authorizations expired over a day for pruning', function () {
    $recent = DeviceAuthorization::factory()->create(['expires_at' => now()->subHours(23)]);
    $old = DeviceAuthorization::factory()->create(['expires_at' => now()->subDays(2)]);

    $ids = (new DeviceAuthorization)->prunable()->pluck('id')->all();

    expect($ids)->toBe([$old->id])->not->toContain($recent->id);
});
