<?php

use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Exceptions\TeleportError;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Teleport\OwnershipMover;

function deviceOwnedThreadWithTeleport(?Device $device = null): array
{
    $device ??= Device::factory()->create();
    $thread = Thread::factory()->create(['owner_side' => 'device', 'owner_device_id' => $device->id]);
    $teleport = Teleport::factory()->up()->inState(TeleportState::Preparing)->create(['thread_id' => $thread->id, 'device_id' => $device->id]);

    return [$thread, $teleport, $device];
}

it('moves a device thread to Yak and finishes the teleport in one step', function () {
    [$thread, $teleport, $device] = deviceOwnedThreadWithTeleport();
    $thread->update(['waiting_for_computer_at' => now()]);

    app(OwnershipMover::class)->toYak($thread, $teleport);

    expect($thread->refresh())
        ->owner_side->toBe(ThreadOwnerSide::Yak)
        ->owner_device_id->toBeNull()
        ->waiting_for_computer_at->toBeNull()
        ->first_teleported_at->not->toBeNull();
    expect($teleport->refresh())->state->toBe(TeleportState::Completed)->completed_at->not->toBeNull();
    expect($device->refresh())->last_teleport_thread_id->toBe($thread->id);
});

it('keeps the first teleport time on a later move', function () {
    [$thread, $teleport] = deviceOwnedThreadWithTeleport();
    $first = now()->subDay()->startOfSecond();
    $thread->update(['first_teleported_at' => $first]);

    app(OwnershipMover::class)->toYak($thread, $teleport);

    expect($thread->refresh()->first_teleported_at->equalTo($first))->toBeTrue();
});

it('moves a Yak thread to a device', function () {
    $device = Device::factory()->create();
    $thread = Thread::factory()->create();
    $teleport = Teleport::factory()->down()->inState(TeleportState::Ready)->create(['thread_id' => $thread->id, 'device_id' => $device->id]);

    app(OwnershipMover::class)->toDevice($thread, $device, $teleport);

    expect($thread->refresh())->owner_side->toBe(ThreadOwnerSide::Device)->owner_device_id->toBe($device->id);
    expect($teleport->refresh()->state)->toBe(TeleportState::Completed);
});

it('changes nothing when the teleport turned terminal in the meantime', function () {
    [$thread, $teleport, $device] = deviceOwnedThreadWithTeleport();
    Teleport::query()->whereKey($teleport->id)->update(['state' => TeleportState::Cancelled]);

    expect(fn () => app(OwnershipMover::class)->toYak($thread, $teleport))->toThrow(TeleportError::class);

    expect($thread->refresh())->owner_side->toBe(ThreadOwnerSide::Device)->owner_device_id->toBe($device->id);
    expect($teleport->refresh()->state)->toBe(TeleportState::Cancelled);
});

it('refuses when the thread no longer belongs to the teleporting device', function () {
    [$thread, $teleport] = deviceOwnedThreadWithTeleport();
    Thread::query()->whereKey($thread->id)->update(['owner_side' => 'yak', 'owner_device_id' => null]);

    expect(fn () => app(OwnershipMover::class)->toYak($thread, $teleport))->toThrow(TeleportError::class, 'changed hands');
    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing);
});

it('no-ops the second of two concurrent moves', function () {
    [$thread, $teleport] = deviceOwnedThreadWithTeleport();
    $mover = app(OwnershipMover::class);
    $stale = Teleport::query()->find($teleport->id);

    $mover->toYak($thread, $teleport);

    expect(fn () => $mover->toYak($thread, $stale))->toThrow(TeleportError::class, 'no longer');
    expect($thread->refresh()->owner_side)->toBe(ThreadOwnerSide::Yak);
});

it('is the only code that writes the owner fields of an existing thread', function () {
    $allowed = [
        'app/Services/Teleport/OwnershipMover.php',
        // Sets the first owner when the thread is created by a teleport up.
        'app/Services/Teleport/TeleportThreadCreator.php',
        // Reads the fields into the API body.
        'app/Services/Teleport/ThreadApiPayload.php',
    ];
    $offenders = [];

    foreach (['app/Services/Teleport', 'app/Http', 'app/Jobs', 'app/Listeners'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            if (preg_match("/['\"]owner_(side|device_id)['\"]\\s*=>|->owner_(side|device_id)\\s*=[^=]/", (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([]);
});
