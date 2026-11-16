<?php

namespace App\Services\Teleport;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Exceptions\TeleportError;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;

/**
 * Decides whether a device may touch a thread's session files right now.
 */
class TeleportAuthorizer
{
    public function forSession(Device $device, Thread $thread, bool $write): Teleport
    {
        $teleport = Teleport::query()
            ->where('thread_id', $thread->id)
            ->where('device_id', $device->id)
            ->whereIn('state', TeleportState::nonTerminal())
            ->latest('id')
            ->first();

        if ($teleport === null && Teleport::query()->where('thread_id', $thread->id)->where('device_id', $device->id)->latest('id')->value('state') === TeleportState::Expired) {
            throw new TeleportError('teleport_expired', 'This teleport expired. Start it again.', 409, ['minutes' => (int) config('yak.teleport.expiry_minutes')]);
        }

        $allowed = $teleport !== null && ($write
            ? $teleport->direction === TeleportDirection::Up && $teleport->state === TeleportState::Open
            : $this->mayRead($teleport));

        if (! $allowed) {
            throw new TeleportError('no_open_teleport', 'There is no open teleport for this thread on this device.');
        }

        return $teleport;
    }

    private function mayRead(Teleport $teleport): bool
    {
        return match ($teleport->direction) {
            TeleportDirection::Up => in_array($teleport->state, [TeleportState::Open, TeleportState::Preparing], true),
            TeleportDirection::Down => $teleport->state === TeleportState::Ready,
        };
    }
}
