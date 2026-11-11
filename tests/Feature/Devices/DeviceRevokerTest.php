<?php

use App\Enums\ThreadOwnerSide;
use App\Events\DeviceRevoked;
use App\Models\Device;
use App\Models\Thread;
use App\Services\Devices\DeviceRevoker;
use Illuminate\Support\Facades\Event;

it('dispatches DeviceRevoked once with the handed back ids', function () {
    Event::fake([DeviceRevoked::class]);
    $device = Device::factory()->create();
    $threads = Thread::factory()->count(2)->ownedByDevice($device->id)->create();

    app(DeviceRevoker::class)->revoke($device);

    Event::assertDispatchedTimes(DeviceRevoked::class, 1);
    Event::assertDispatched(DeviceRevoked::class, fn (DeviceRevoked $event) => $event->device->is($device)
        && collect($event->handedBackThreadIds)->sort()->values()->all() === $threads->pluck('id')->sort()->values()->all());
    expect($threads->first()->fresh()->owner_side)->toBe(ThreadOwnerSide::Yak)
        ->and($threads->first()->fresh()->owner_device_id)->toBeNull();
});

it('dispatches with an empty list for a device with no threads', function () {
    Event::fake([DeviceRevoked::class]);
    $device = Device::factory()->create();

    app(DeviceRevoker::class)->revoke($device);

    Event::assertDispatched(DeviceRevoked::class, fn (DeviceRevoked $event) => $event->handedBackThreadIds === []);
    expect($device->fresh()->isRevoked())->toBeTrue();
});

it('does nothing for an already revoked device', function () {
    Event::fake([DeviceRevoked::class]);

    app(DeviceRevoker::class)->revoke(Device::factory()->revoked()->create());

    Event::assertNotDispatched(DeviceRevoked::class);
});
