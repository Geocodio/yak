<?php

use App\Enums\TeleportState;
use App\Models\Device;
use App\Models\HeldThreadEvent;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function teleportProp(User $viewer, Thread $thread): array
{
    $teleport = [];
    test()->actingAs($viewer)->get(route('threads.show', $thread))
        ->assertInertia(function (Assert $page) use (&$teleport): void {
            $teleport = $page->toArray()['props']['teleport'];
        });

    return $teleport;
}

it('is none for a plain Yak thread', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();

    $teleport = teleportProp($user, $thread);

    expect($teleport['state'])->toBe('none')
        ->and($teleport['command'])->toBe("yak teleport {$thread->id}")
        ->and($teleport['link'])->toBe("yak://teleport/{$thread->id}")
        ->and($teleport['canTeleport'])->toBeTrue()
        ->and($teleport['hasDevices'])->toBeFalse()
        ->and($teleport['ownerDevice'])->toBeNull()
        ->and($teleport['heldEvents'])->toBe([]);
});

it('is waiting once requested', function () {
    $thread = Thread::factory()->create(['waiting_for_computer_at' => now(), 'teleport_requested_running_turn' => 'wait']);

    $teleport = teleportProp(User::factory()->create(), $thread);

    expect($teleport['state'])->toBe('waiting')->and($teleport['requestedRunningTurn'])->toBe('wait')->and($teleport['since'])->not->toBeNull();
});

it('is teleporting during a down teleport', function () {
    $thread = Thread::factory()->create(['waiting_for_computer_at' => now()]);
    Teleport::factory()->down()->inState(TeleportState::WaitingForTurn)->create(['thread_id' => $thread->id]);

    $teleport = teleportProp(User::factory()->create(), $thread);

    expect($teleport['state'])->toBe('teleporting')->and($teleport['openTeleportState'])->toBe('waiting_for_turn');
});

it('is on_device for the owner, with held events and an environment note', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'name' => 'Work Mac']);
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['owner_changed_at' => now()->subHour()]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'created_at' => now()->subDays(2)]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'delivered_at' => now()]);

    $teleport = teleportProp($user, $thread);

    expect($teleport['state'])->toBe('on_device')
        ->and($teleport['viewerIsOwner'])->toBeTrue()
        ->and($teleport['ownerDevice'])->toBe(['id' => $device->id, 'name' => 'Work Mac'])
        ->and($teleport['canTeleport'])->toBeTrue()
        ->and($teleport['hasDevices'])->toBeTrue()
        ->and($teleport['heldEvents'])->toHaveCount(2)
        ->and(collect($teleport['heldEvents'])->pluck('isTinted')->sort()->values()->all())->toBe([false, true]);
});

it('hides the command and device from a teammate of a device-owned team thread', function () {
    $owner = User::factory()->create(['name' => 'Ada']);
    $thread = Thread::factory()->ownedByDevice(Device::factory()->create(['user_id' => $owner->id, 'name' => 'Work Mac'])->id)->create();

    $teleport = teleportProp(User::factory()->create(), $thread);

    expect($teleport['state'])->toBe('on_device')
        ->and($teleport['viewerIsOwner'])->toBeFalse()
        ->and($teleport['ownerDevice'])->toBeNull()
        ->and($teleport['ownerName'])->toBe('Ada')
        ->and($teleport['command'])->toBe('')
        ->and($teleport['canTeleport'])->toBeFalse();
});

it('reports a failed teleport', function () {
    $thread = Thread::factory()->create();
    Teleport::factory()->down()->inState(TeleportState::Failed)->create(['thread_id' => $thread->id, 'failure_code' => 'push_failed', 'failure_message' => 'Push was rejected.']);

    $teleport = teleportProp(User::factory()->create(), $thread);

    expect($teleport['state'])->toBe('none')->and($teleport['failure'])->toBe(['code' => 'push_failed', 'message' => 'Push was rejected.']);
});

it('links the thread it continued from', function () {
    $from = Thread::factory()->create(['title' => 'Fix login']);
    $thread = Thread::factory()->create(['continued_from_thread_id' => $from->id]);
    $teleport = teleportProp(User::factory()->create(), $thread);

    expect($teleport['continuedFromThreadId'])->toBe($from->id)->and($teleport['continuedFromTitle'])->toBe('Fix login');
});

it('reports the last completed teleport as an environment switch', function () {
    $thread = Thread::factory()->create();
    $device = Device::factory()->create();
    $viewer = User::factory()->create();

    expect(teleportProp($viewer, $thread)['environmentSwitch'])->toBeNull();

    Teleport::factory()->up()->inState(TeleportState::Completed)->create(['thread_id' => $thread->id, 'device_id' => $device->id, 'completed_at' => '2026-11-11 10:00:00']);
    expect(teleportProp($viewer, $thread)['environmentSwitch'])->toMatchArray(['from' => 'your computer', 'to' => 'Yak']);

    Teleport::factory()->down()->inState(TeleportState::Completed)->create(['thread_id' => $thread->id, 'device_id' => $device->id, 'completed_at' => '2026-11-11 12:00:00']);
    expect(teleportProp($viewer, $thread)['environmentSwitch'])->toMatchArray(['from' => 'Yak', 'to' => 'your computer']);
});

it('exposes the teleport block as an Inertia prop', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page->has('teleport.state')->has('teleport.canTeleport'));
});
