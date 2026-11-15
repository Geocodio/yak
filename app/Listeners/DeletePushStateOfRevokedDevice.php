<?php

namespace App\Listeners;

use App\Events\DeviceRevoked;
use App\Models\DeviceLiveActivity;
use App\Models\DevicePushToken;

/**
 * A revoked device gets no push: its tokens and Live Activity rows are removed.
 */
class DeletePushStateOfRevokedDevice
{
    public function handle(DeviceRevoked $event): void
    {
        DevicePushToken::query()->where('device_id', $event->device->id)->delete();
        DeviceLiveActivity::query()->where('device_id', $event->device->id)->delete();
    }
}
