<?php

namespace App\Jobs\Middleware;

use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Refuses to run a task against a repository that hasn't been set up yet.
 *
 * A repo is "set up" when `SetupYakJob` has built its sandbox template
 * and stored the snapshot reference on the row. Without that, the agent
 * would fall back to the empty `yak-base` template and the task would
 * have no repository contents to work with.
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

        if ($repository !== null && ! empty($repository->sandbox_snapshot)) {
            $next($job);

            return;
        }

        $reason = $repository === null
            ? "Repository '{$task->repo}' not found"
            : "Repository '{$task->repo}' has not been set up yet — run Setup before dispatching tasks";

        Log::channel('yak')->warning('Refusing task on un-set-up repository', [
            'job' => $job::class,
            'task_id' => $task->id,
            'repo' => $task->repo,
        ]);

        // Guard against invalid state transitions (e.g. from
        // AwaitingClarification). We still want the task marked done even if
        // the state machine refuses, so fall back to a direct attribute set.
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
}
