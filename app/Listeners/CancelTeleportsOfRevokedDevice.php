<?php

namespace App\Listeners;

use App\Events\DeviceRevoked;
use App\Services\Teleport\Teleports;

/**
 * A revoked device can no longer finish a teleport. Owner fields are the revoker's to hand back.
 */
class CancelTeleportsOfRevokedDevice
{
    public function __construct(private readonly Teleports $teleports) {}

    public function handle(DeviceRevoked $event): void
    {
        $this->teleports->cancelAllForDevice($event->device);
    }
}
