<?php

namespace App\Console\Commands;

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Services\Devices\DeviceRevoker;
use Illuminate\Console\Command;

class RevokeIdlePhonesCommand extends Command
{
    protected $signature = 'yak:revoke-idle-phones';

    protected $description = 'Revoke phones that have not connected for the configured number of idle days';

    public function handle(DeviceRevoker $revoker): int
    {
        $cutoff = now()->subDays((int) config('yak.pairing.idle_days'));
        $revoked = 0;

        Device::query()
            ->active()
            ->where('kind', DeviceKind::Phone)
            ->where(fn ($query) => $query
                ->where('last_seen_at', '<', $cutoff)
                ->orWhere(fn ($never) => $never->whereNull('last_seen_at')->where('created_at', '<', $cutoff)))
            ->each(function (Device $device) use ($revoker, &$revoked): void {
                $revoker->revoke($device);
                $revoked++;
            });

        $this->info("Revoked {$revoked} idle phones.");

        return self::SUCCESS;
    }
}
