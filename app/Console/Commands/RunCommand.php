<?php

namespace App\Console\Commands;

use App\Enums\TaskMode;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:run {id} {description} {--repo=} {--context=} {--research} {--sync}')]
#[Description('Create and dispatch a task manually')]
class RunCommand extends Command
{
    public function handle(): int
    {
        /** @var string $externalId */
        $externalId = $this->argument('id');

        /** @var string $description */
        $description = $this->argument('description');

        /** @var string|null $repoSlug */
        $repoSlug = $this->option('repo');

        /** @var string|null $context */
        $context = $this->option('context');

        $isResearch = (bool) $this->option('research');
        $isSync = (bool) $this->option('sync');

        $repository = $this->resolveRepository($repoSlug);

        if (! $repository) {
            return self::FAILURE;
        }

        $mode = $isResearch ? TaskMode::Research : TaskMode::Fix;

        $task = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => $externalId,
            'mode' => $mode,
            'description' => $description,
            'context' => $context,
            'source' => 'cli',
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'cli', 'repo' => $repository->slug]);

        $jobClass = $isResearch ? ResearchYakJob::class : RunYakJob::class;
        $dispatcher = app(AgentJobDispatcher::class);

        if ($isSync) {
            $this->components->info("Running task #{$task->id} synchronously...");
            $dispatcher->dispatchSync($task, $jobClass);
        } else {
            $dispatcher->dispatch($task, $jobClass);
        }

        $this->components->info("Task #{$task->id} dispatched for {$repository->name}.");

        return self::SUCCESS;
    }

    private function resolveRepository(?string $slug): ?Repository
    {
        if ($slug !== null) {
            $repository = Repository::where('slug', $slug)->first();

            if (! $repository) {
                $this->components->error("Repository '{$slug}' not found.");

                return null;
            }

            return $repository;
        }

        $repository = Repository::where('is_default', true)->first();

        if (! $repository) {
            $this->components->error('No default repository configured. Use --repo= to specify one.');

            return null;
        }

        return $repository;
    }
}
