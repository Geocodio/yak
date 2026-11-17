<?php

namespace App\Jobs\Middleware;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Repos\RepositoryTemplateDrift;
use App\Services\Threads\SetupScriptHash;
use Closure;
use Illuminate\Support\Facades\Log;

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

        if ($task->thread_id !== null && $this->waitForTemplate($task, $repository)) {
            return;
        }

        if (empty($repository->sandbox_snapshot)) {
            $this->refuse(
                $job,
                $task,
                "Repository '{$task->repo}' has not been set up yet. Run Setup from its settings, then retry: " . route('repos.edit', $repository),
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
            $setupTask = app(RepositoryTemplateDrift::class)->dispatchSetupTask($repository);

            $reason = sprintf(
                'Sandbox base image updated (%s → v%d). A fresh Setup task (#%d) has been dispatched — retry this task once setup completes.',
                $storedLabel,
                $currentVersion,
                $setupTask->id,
            );

            $this->refuse($job, $task, $reason);

            return;
        }

        if ($this->setupScriptChanged($repository)) {
            $sandbox->invalidateTemplate($repository);
            $setupTask = app(RepositoryTemplateDrift::class)->dispatchSetupTask($repository, '.yak/setup.sh changed');

            $this->refuse(
                $job,
                $task,
                sprintf('.yak/setup.sh changed. A fresh Setup task (#%d) has been dispatched. Retry this task once setup completes.', $setupTask->id),
            );

            return;
        }

        $next($job);
    }

    /**
     * A thread task whose repository template is stale, or already being rebuilt, does not
     * fail: the thread waits for the repository and ThreadsAwaitingRepository starts the
     * task once setup succeeds. A stale template is invalidated and rebuilt here, once.
     */
    private function waitForTemplate(YakTask $task, Repository $repository): bool
    {
        if (! app(RepositoryTemplateDrift::class)->mustWait($repository)) {
            return false;
        }

        $task->update(['status' => TaskStatus::Pending]);

        Thread::query()->withoutGlobalScopes()->whereKey($task->thread_id)->update([
            'blocked_reason' => ThreadBlockReason::WaitingForRepository,
            'blocked_at' => now(),
        ]);

        return true;
    }

    /** A script missing on the default branch, or a failed read, never triggers a rebuild. */
    private function setupScriptChanged(Repository $repository): bool
    {
        try {
            $current = app(SetupScriptHash::class)->forDefaultBranch($repository);
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }

        return $current !== null && $current !== $repository->setup_script_hash;
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

        if ($task->source !== 'system') {
            SendNotificationJob::dispatch($task, NotificationType::Error, $reason);
        }

        if (method_exists($job, 'fail')) {
            $job->fail(new \RuntimeException($reason));
        }
    }
}
