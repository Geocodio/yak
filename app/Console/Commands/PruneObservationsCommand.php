<?php

namespace App\Console\Commands;

use App\Models\Observation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:observations:prune')]
#[Description('Delete observations past the retention window')]
class PruneObservationsCommand extends Command
{
    public function handle(): int
    {
        $days = (int) config('yak.ci_scan.observation_retention_days', 90);
        $cutoff = now()->subDays($days);

        $deleted = Observation::query()->where('created_at', '<', $cutoff)->delete();

        $this->components->info("Pruned {$deleted} observation(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
