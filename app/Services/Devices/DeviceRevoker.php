<?php

namespace App\Services\Devices;

use App\Models\Device;

/**
 * Revokes devices. The row stays so the API can answer device_revoked.
 */
class DeviceRevoker
{
    public function revoke(Device $device): void
    {
        if ($device->isRevoked()) {
            return;
        }

        $device->forceFill(['revoked_at' => now()])->save();
    }
}
