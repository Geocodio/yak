<?php

namespace App\Console\Commands;

use App\Jobs\RenderVideoJob;
use App\Jobs\RenderWalkthroughJob;
use App\Models\Artifact;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;

#[Signature('yak:video:rerender {--task= : Only this task id} {--failed-since= : Only raw videos created on/after this date (YYYY-MM-DD)} {--dry-run : List without dispatching}')]
#[Description('Re-dispatch RenderVideoJob for raw walkthrough videos that have a storyboard but no rendered cut, and RenderWalkthroughJob for v3 tasks with a manifest but no rendered cut')]
class RerenderVideosCommand extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('artifacts');

        $query = Artifact::query()
            ->rawFootage()
            ->whereNotExists(function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('artifacts as cuts')
                    ->whereColumn('cuts.yak_task_id', 'artifacts.yak_task_id')
                    ->where('cuts.role', 'cut')
                    ->whereColumn('cuts.created_at', '>=', 'artifacts.created_at');
            })
            ->orderBy('id');

        if (($task = $this->option('task')) !== null) {
            $query->where('yak_task_id', (int) $task);
        }
        if (($since = $this->option('failed-since')) !== null) {
            $query->where('created_at', '>=', $since . ' 00:00:00');
        }

        $candidates = $query->get()->filter(
            fn (Artifact $raw): bool => $disk->exists(dirname($raw->disk_path) . '/storyboard.json')
        );

        foreach ($candidates as $raw) {
            $this->line("Task #{$raw->yak_task_id}: {$raw->filename} (artifact {$raw->id})");
        }

        $manifestQuery = Artifact::query()
            ->role('manifest')
            ->whereNotExists(function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('artifacts as cuts')
                    ->whereColumn('cuts.yak_task_id', 'artifacts.yak_task_id')
                    ->where('cuts.role', 'cut')
                    ->whereColumn('cuts.created_at', '>=', 'artifacts.created_at');
            })
            ->orderBy('id');

        if (($task = $this->option('task')) !== null) {
            $manifestQuery->where('yak_task_id', (int) $task);
        }
        if (($since = $this->option('failed-since')) !== null) {
            $manifestQuery->where('created_at', '>=', $since . ' 00:00:00');
        }

        $manifests = $manifestQuery->get()->unique('yak_task_id');

        foreach ($manifests as $manifest) {
            $this->line("Task #{$manifest->yak_task_id}: v3 walkthrough (manifest artifact {$manifest->id})");
        }

        if ($this->option('dry-run')) {
            $this->components->info("Would dispatch {$candidates->count()} render(s)");
            $this->components->info("Would dispatch {$manifests->count()} v3 walkthrough(s)");

            return self::SUCCESS;
        }

        foreach ($candidates as $raw) {
            RenderVideoJob::dispatch($raw->id);
        }

        foreach ($manifests as $manifest) {
            RenderWalkthroughJob::dispatch($manifest->yak_task_id);
        }

        $this->components->info("Dispatched {$candidates->count()} render(s)");
        $this->components->info("Dispatched {$manifests->count()} v3 walkthrough(s)");

        return self::SUCCESS;
    }
}
