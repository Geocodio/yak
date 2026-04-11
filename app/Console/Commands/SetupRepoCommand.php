<?php

namespace App\Console\Commands;

use App\Enums\TaskMode;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('yak:setup-repo {slug}')]
#[Description('Dispatch a setup task for a repository')]
class SetupRepoCommand extends Command
{
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $repository = Repository::where('slug', $slug)->first();

        if (! $repository) {
            $this->components->error("Repository '{$slug}' not found.");

            return self::FAILURE;
        }

        $task = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => 'setup-' . Str::random(8),
            'mode' => TaskMode::Setup,
            'description' => "Setup repository: {$repository->name}",
            'source' => 'cli',
        ]);

        $repository->update([
            'setup_task_id' => $task->id,
            'setup_status' => 'pending',
        ]);

        SetupYakJob::dispatch($task);

        $this->components->info("Setup task dispatched for {$repository->name} (task #{$task->id}).");

        return self::SUCCESS;
    }
}
