<?php

namespace App\Console\Commands;

use App\Facades\Telemetry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One `queue.sampled` event per queue per minute: how many jobs are
 * waiting and how old the oldest one is. Reads the `jobs` table directly
 * because the queue is database-backed and there is no Horizon.
 */
#[Signature('yak:telemetry:sample-queues')]
#[Description('Record queue depth and oldest-job age for each queue')]
class SampleQueueDepthCommand extends Command
{
    public function handle(): int
    {
        if (! Telemetry::enabled() || ! (bool) config('yak.telemetry.queue_sampling', true)) {
            return self::SUCCESS;
        }

        $now = now()->getTimestamp();

        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as depth, MIN(available_at) as oldest_available_at, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved')
            ->groupBy('queue')
            ->get();

        foreach ($rows as $row) {
            $oldestAgeSeconds = max(0, $now - (int) $row->oldest_available_at);

            Telemetry::record('queue.sampled', [
                'queue' => (string) $row->queue,
                'reserved' => (int) $row->reserved,
                'oldest_age_s' => $oldestAgeSeconds,
            ], value: (float) $row->depth);
        }

        return self::SUCCESS;
    }
}
