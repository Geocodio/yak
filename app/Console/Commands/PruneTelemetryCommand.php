<?php

namespace App\Console\Commands;

use App\Models\TelemetryEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:telemetry:prune')]
#[Description('Delete telemetry events past the retention window (task_runs are kept)')]
class PruneTelemetryCommand extends Command
{
    public function handle(): int
    {
        $days = (int) config('yak.telemetry.retention_days', 90);
        $cutoff = now()->subDays($days);

        $deleted = TelemetryEvent::query()->where('occurred_at', '<', $cutoff)->delete();

        $this->components->info("Pruned {$deleted} telemetry event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
