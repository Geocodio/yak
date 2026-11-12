<?php

use App\Enums\TeleportState;
use App\Models\Device;
use App\Models\HeldThreadEvent;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Teleport\ThreadApiPayload;

function payloadFor(Thread $thread, Device $device): array
{
    return app(ThreadApiPayload::class)->build($thread->fresh(), $device);
}

it('has no owner device for a Yak-owned thread', function () {
    [$device] = makeDevice();
    $thread = Thread::factory()->create();

    expect(payloadFor($thread, $device)['owner_device'])->toBeNull();
});

it('flags whether the owner device is this one', function () {
    [$device] = makeDevice();
    $other = Device::factory()->create();
    $mine = Thread::factory()->ownedByDevice($device->id)->create();
    $theirs = Thread::factory()->ownedByDevice($other->id)->create();

    expect(payloadFor($mine, $device)['owner_device'])->toBe(['id' => $device->id, 'name' => $device->name, 'is_this_device' => true])
        ->and(payloadFor($theirs, $device)['owner_device']['is_this_device'])->toBeFalse();
});

it('fills last_teleport_up from the latest completed up teleport', function () {
    [$device] = makeDevice();
    $thread = Thread::factory()->create(['branch' => 'yak/x']);
    expect(payloadFor($thread, $device)['last_teleport_up'])->toBeNull();

    Teleport::factory()->up()->inState(TeleportState::Failed)->create(['thread_id' => $thread->id]);
    Teleport::factory()->down()->inState(TeleportState::Completed)->create(['thread_id' => $thread->id]);
    $up = Teleport::factory()->up()->inState(TeleportState::Completed)->create([
        'thread_id' => $thread->id,
        'device_id' => $device->id,
        'completed_at' => now(),
        'branch_heads' => ['yak/x' => 'abc123'],
    ]);

    expect(payloadFor($thread, $device)['last_teleport_up'])->toMatchArray([
        'teleport_id' => $up->id,
        'branch' => 'yak/x',
        'head_sha' => 'abc123',
        'device_id' => $device->id,
    ])->toHaveKey('at');
});

it('counts only undelivered held events', function () {
    [$device] = makeDevice();
    $thread = Thread::factory()->create();
    HeldThreadEvent::factory()->count(2)->create(['thread_id' => $thread->id]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'delivered_at' => now()]);

    expect(payloadFor($thread, $device)['held_event_count'])->toBe(2);
});
