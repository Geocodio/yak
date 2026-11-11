<?php

namespace App\Services\Teleport;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Exceptions\TeleportError;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * The only code that moves a thread between Yak and a device after it exists.
 * Each move is one locked transaction and finishes the teleport.
 */
class OwnershipMover
{
    public function toYak(Thread $thread, Teleport $teleport): void
    {
        $this->move($thread, $teleport, TeleportDirection::Up, function (Thread $locked, Teleport $locking): void {
            if ($locked->owner_side !== ThreadOwnerSide::Device || $locked->owner_device_id !== $locking->device_id) {
                throw new TeleportError('ownership_changed', 'The thread changed hands while this teleport ran.');
            }

            $locked->forceFill([
                'owner_side' => ThreadOwnerSide::Yak,
                'owner_device_id' => null,
                'first_teleported_at' => $locked->first_teleported_at ?? now(),
            ]);
        });
    }

    public function toDevice(Thread $thread, Device $device, Teleport $teleport): void
    {
        $this->move($thread, $teleport, TeleportDirection::Down, function (Thread $locked) use ($device): void {
            if ($locked->owner_side !== ThreadOwnerSide::Yak) {
                throw new TeleportError('ownership_changed', 'The thread changed hands while this teleport ran.');
            }

            $locked->forceFill(['owner_side' => ThreadOwnerSide::Device, 'owner_device_id' => $device->id]);
        });
    }

    /**
     * @param  callable(Thread, Teleport): void  $assign
     */
    private function move(Thread $thread, Teleport $teleport, TeleportDirection $direction, callable $assign): void
    {
        DB::transaction(function () use ($thread, $teleport, $direction, $assign): void {
            $lockedThread = Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($thread->id);
            $lockedTeleport = Teleport::query()->lockForUpdate()->findOrFail($teleport->id);

            if ($lockedTeleport->state->isTerminal() || $lockedTeleport->direction !== $direction) {
                throw new TeleportError('teleport_not_open', 'This teleport can no longer move the thread.');
            }

            $assign($lockedThread, $lockedTeleport);

            $lockedThread->forceFill([
                'owner_changed_at' => now(),
                'waiting_for_computer_at' => null,
                'teleport_requested_running_turn' => null,
            ])->save();

            $lockedTeleport->forceFill(['state' => TeleportState::Completed, 'completed_at' => now()])->save();

            Device::query()->whereKey($lockedTeleport->device_id)->update([
                'last_teleport_thread_id' => $lockedThread->id,
                'last_teleport_at' => now(),
            ]);

            $thread->setRawAttributes($lockedThread->getAttributes(), true);
            $teleport->setRawAttributes($lockedTeleport->getAttributes(), true);
        });
    }
}
