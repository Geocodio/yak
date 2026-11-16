<?php

namespace App\Services\Devices;

use App\Enums\ThreadOwnerSide;
use App\Events\DeviceRevoked;
use App\Models\Device;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Revokes devices. The row stays so the API can answer device_revoked, and threads the device owned return to Yak as they were last teleported.
 */
class DeviceRevoker
{
    public function revoke(Device $device): void
    {
        if ($device->isRevoked()) {
            return;
        }

        DB::transaction(function () use ($device): void {
            $device->forceFill(['revoked_at' => now()])->save();

            $handedBackThreadIds = Thread::query()->withoutGlobalScopes()
                ->where('owner_device_id', $device->id)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            Thread::query()->withoutGlobalScopes()
                ->whereIn('id', $handedBackThreadIds)
                ->update(['owner_side' => ThreadOwnerSide::Yak->value, 'owner_device_id' => null]);

            DB::afterCommit(fn () => DeviceRevoked::dispatch($device, $handedBackThreadIds));
        });
    }
}
