<?php

namespace App\Jobs\Middleware;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Refuses to run a task against a repository that hasn't been set up yet,
 * or whose sandbox template was built from an outdated yak-base image.
 *
 * A repo is "set up" when `SetupYakJob` has built its sandbox template
 * and stored the snapshot reference on the row. Without that, the agent
 * would fall back to the empty `yak-base` template and the task would
 * have no repository contents to work with.
 *
 * A repo is "current" when its stored `sandbox_base_version` matches
 * `config('yak.sandbox.base_version')`. On drift, the stale template is
 * destroyed and a fresh `SetupYakJob` is dispatched automatically; the
 * current task is failed with a clear message so the user can retry once
 * setup completes.
 */
class EnsureRepoReady
{
    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        $task = $job->task ?? null;

        if (! $task instanceof YakTask) {
            $next($job);

            return;
        }

        $repository = Repository::where('slug', $task->repo)->first();

        if ($repository === null) {
            $this->refuse($job, $task, "Repository '{$task->repo}' not found");

            return;
        }

        if (empty($repository->sandbox_snapshot)) {
            $this->refuse(
                $job,
                $task,
                "Repository '{$task->repo}' has not been set up yet — run Setup before dispatching tasks",
            );

            return;
        }

        $sandbox = app(IncusSandboxManager::class);

        if (! $sandbox->isTemplateUpToDate($repository)) {
            $currentVersion = (int) config('yak.sandbox.base_version', 1);
            $storedLabel = $repository->sandbox_base_version === null
                ? 'legacy (unversioned)'
                : 'v' . (int) $repository->sandbox_base_version;

            $sandbox->invalidateTemplate($repository);
            $setupTask = $this->dispatchSetupTask($repository);

            $reason = sprintf(
                'Sandbox base image updated (%s → v%d). A fresh Setup task (#%d) has been dispatched — retry this task once setup completes.',
                $storedLabel,
                $currentVersion,
                $setupTask->id,
            );

            $this->refuse($job, $task, $reason);

            return;
        }

        $next($job);
    }

    private function refuse(object $job, YakTask $task, string $reason): void
    {
        Log::channel('yak')->warning('Refusing task on un-ready repository', [
            'job' => $job::class,
            'task_id' => $task->id,
            'repo' => $task->repo,
            'reason' => $reason,
        ]);

        // Guard against invalid state transitions (e.g. from
        // AwaitingClarification). We still want the task marked done even
        // if the state machine refuses, so fall back to a direct set.
        try {
            $task->update([
                'status' => TaskStatus::Failed,
                'error_log' => $reason,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $task->forceFill([
                'error_log' => $reason,
                'completed_at' => now(),
            ])->save();
        }

        if (method_exists($job, 'fail')) {
            $job->fail(new \RuntimeException($reason));
        }
    }

    private function dispatchSetupTask(Repository $repository): YakTask
    {
        $setupTask = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => 'setup-' . Str::random(8),
            'mode' => TaskMode::Setup,
            'description' => "Re-provision sandbox for {$repository->name} (yak-base updated)",
            'source' => 'system',
        ]);

        $repository->update([
            'setup_task_id' => $setupTask->id,
            'setup_status' => 'pending',
        ]);

        SetupYakJob::dispatch($setupTask);

        return $setupTask;
    }
}
