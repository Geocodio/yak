<?php

namespace App\Jobs\Concerns;

use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;

/**
 * Atomic, per-instance-idempotent task claim shared by every job that
 * picks up a YakTask.
 *
 * `ClaimsTaskAtomically` (job middleware) calls `claimTask()` before
 * `EnsureRepoReady`/`EnsureDailyBudget` can run, so a losing copy is
 * deleted before it ever reaches a path that calls `$job->fail()` —
 * which would otherwise mark the winning copy's task Failed and destroy
 * its live sandbox.
 *
 * `handle()` also calls `claimTask()` itself, unconditionally, as the
 * first thing the run does. Against the real queue that's a harmless
 * no-op: the middleware already claimed the task and `$taskClaimed`
 * short-circuits the second call. When a job is constructed and
 * `->handle()` is called directly — bypassing the queue's middleware
 * pipeline entirely, as this app's job tests do — this second call is
 * what actually performs the claim, preserving the existing contract
 * that `->handle()` alone drives a Pending task through to completion.
 */
trait ClaimsTask
{
    /**
     * Set when this instance lost the claim race, so a `failed()` handler
     * can no-op instead of treating the loss as a real failure.
     */
    public bool $taskClaimLost = false;

    private bool $taskClaimed = false;

    /**
     * Public so `ClaimsTaskAtomically` can call it from outside the job.
     */
    public function claimTask(TaskStatus $from = TaskStatus::Pending): bool
    {
        if ($this->taskClaimed) {
            return true;
        }

        $claimed = YakTask::where('id', $this->task->id)
            ->where('status', $from->value)
            ->update([
                'status' => TaskStatus::Running->value,
                'started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            $this->taskClaimLost = true;

            return false;
        }

        $this->task->refresh();
        $this->taskClaimed = true;

        return true;
    }
}
