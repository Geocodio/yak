<?php

namespace App\Console\Commands;

use App\Models\DeviceLiveActivity;
use App\Models\Thread;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use Illuminate\Console\Command;

class PruneLiveActivityChannelsCommand extends Command
{
    /** Hours a Live Activity row can stay: the window of the three-limit. */
    private const ACTIVITY_ROW_HOURS = 8;

    /** Days without a run before a thread's broadcast channel is deleted. */
    private const IDLE_CHANNEL_DAYS = 7;

    protected $signature = 'yak:live-activities:prune-channels {--chunk=500 : Rows read per batch}';

    protected $description = 'Delete broadcast channels of idle or closed threads and leftover Live Activity rows';

    public function handle(LiveActivityChannels $channels): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $idleBefore = now()->subDays(self::IDLE_CHANNEL_DAYS);
        $deleted = 0;

        Thread::withoutGlobalScopes()
            ->whereNotNull('live_activity_channel')
            ->where('live_activity_channel', '!=', '')
            ->where(fn ($query) => $query
                ->whereNotNull('closed_at')
                ->orWhere(fn ($idle) => $idle
                    ->whereDoesntHave('runs', fn ($runs) => $runs->where('started_at', '>=', $idleBefore))
                    ->where('created_at', '<', $idleBefore)))
            ->lazyById($chunk)
            ->each(function (Thread $thread) use ($channels, &$deleted): void {
                $channels->deleteLater($thread);
                $deleted++;
            });

        $rows = 0;
        $rowCutoff = now()->subHours(self::ACTIVITY_ROW_HOURS);

        DeviceLiveActivity::query()
            ->where('started_at', '<', $rowCutoff)
            ->pluck('id')
            ->chunk($chunk)
            ->each(function ($ids) use (&$rows): void {
                $rows += DeviceLiveActivity::query()->whereKey($ids->all())->delete();
            });

        $this->info("Queued {$deleted} channel deletes and removed {$rows} leftover activity rows.");

        return self::SUCCESS;
    }
}
