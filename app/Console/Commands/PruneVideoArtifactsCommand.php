<?php

namespace App\Console\Commands;

use App\Models\Artifact;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;

#[Signature('yak:video:prune {--dry-run : Report without deleting}')]
#[Description('Delete raw footage, shot clips and voiceover audio for tasks whose rendered cut is older than the retention window')]
class PruneVideoArtifactsCommand extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) config('yak.video.raw_retention_days', 30));
        $cutoff = now()->subDays($days);
        $disk = Storage::disk('artifacts');

        $raws = Artifact::query()
            ->whereIn('role', ['raw', 'shot', 'voiceover'])
            ->whereExists(function (Builder $sub) use ($cutoff): void {
                $sub->selectRaw('1')
                    ->from('artifacts as cuts')
                    ->whereColumn('cuts.yak_task_id', 'artifacts.yak_task_id')
                    ->where('cuts.role', 'cut')
                    ->where('cuts.created_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->get();

        if ($this->option('dry-run')) {
            foreach ($raws as $raw) {
                $this->line("Task #{$raw->yak_task_id}: {$raw->disk_path}");
            }
            $this->components->info("Would prune {$raws->count()} artifact(s) (cut older than {$days} days)");

            return self::SUCCESS;
        }

        $pruned = 0;
        foreach ($raws as $raw) {
            if ($disk->exists($raw->disk_path)) {
                $disk->delete($raw->disk_path);
            }
            $raw->delete();
            $pruned++;
        }

        $this->components->info("Pruned {$pruned} artifact(s) (cut older than {$days} days)");

        return self::SUCCESS;
    }
}
