<?php

namespace App\Services\Threads\Repos;

use App\Enums\TaskMode;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\IncusSandboxManager;
use App\Services\ResponsiblePersonResolver;
use Illuminate\Support\Str;

/**
 * Keeps sandboxes from being cloned off a repository template that was built from an
 * outdated yak-base image. A stale template is destroyed and a Setup task rebuilds it; the
 * callers wait for that setup instead of cloning.
 */
class RepositoryTemplateDrift
{
    public function __construct(
        private readonly IncusSandboxManager $sandbox,
        private readonly AgentJobDispatcher $dispatcher,
    ) {}

    /**
     * True when the repository has no template a sandbox may be cloned from right now: the
     * template is stale (it is invalidated and one Setup task is dispatched), or a setup is
     * already rebuilding it.
     */
    public function mustWait(Repository $repository): bool
    {
        if (! $this->sandbox->isTemplateUpToDate($repository)) {
            $this->invalidateAndSetUp($repository);

            return true;
        }

        return empty($repository->sandbox_snapshot) && in_array($repository->setup_status, ['pending', 'running'], true);
    }

    public function invalidateAndSetUp(Repository $repository, string $reason = 'yak-base updated'): YakTask
    {
        if (($inFlight = $repository->inFlightSetupTask()) !== null) {
            return $inFlight;
        }

        $this->sandbox->invalidateTemplate($repository);

        return $this->dispatchSetupTask($repository, $reason);
    }

    public function dispatchSetupTask(Repository $repository, string $reason = 'yak-base updated'): YakTask
    {
        if (($inFlight = $repository->inFlightSetupTask()) !== null) {
            return $inFlight;
        }

        $setupTask = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => 'setup-' . Str::random(8),
            'mode' => TaskMode::Setup,
            'description' => "Re-provision sandbox for {$repository->name} ({$reason})",
            'source' => 'system',
            'responsible_name' => app(ResponsiblePersonResolver::class)->resolve(null, null, $repository->slug),
        ]);

        $repository->update([
            'setup_task_id' => $setupTask->id,
            'setup_status' => 'pending',
        ]);

        $this->dispatcher->dispatch($setupTask, SetupYakJob::class);

        return $setupTask;
    }
}
