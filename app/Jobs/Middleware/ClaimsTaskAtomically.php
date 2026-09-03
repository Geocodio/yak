<?php

namespace App\Jobs\Middleware;

use App\Enums\TaskStatus;
use App\Models\YakTask;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Atomically claims a job's task before any downstream middleware or
 * `handle()` runs, so two copies of the same job — a duplicate dispatch,
 * a redelivered queue message, a stale copy left behind by a drain
 * release — can never both proceed.
 *
 * The actual claim (a single conditional `UPDATE`, not a fresh Eloquent
 * read-then-write) lives on the job itself via the `ClaimsTask` trait,
 * so a job constructed directly and run with `->handle()` — bypassing
 * this middleware entirely, as this app's job tests do — still claims
 * its task the same way.
 *
 * Must run before `EnsureRepoReady` and `EnsureDailyBudget` — both call
 * `$job->fail()` on refusal, which would otherwise mark the (actually
 * Running) task Failed and destroy the winning copy's live sandbox
 * (`IncusSandboxManager::create()` reclaims a same-named container by
 * destroying it). It can safely run after `PausesDuringDrain` and
 * `HoldsForClaudeAuth`, since those only release the job back to the
 * queue — the task is still Pending when they act, so a later retry of
 * the same job claims normally instead of finding itself already
 * "claimed" and deleting itself.
 */
class ClaimsTaskAtomically
{
    public function __construct(private readonly TaskStatus $from = TaskStatus::Pending) {}

    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        $task = $job->task ?? null;

        if (! $task instanceof YakTask || ! method_exists($job, 'claimTask')) {
            $next($job);

            return;
        }

        if ($job->claimTask($this->from)) {
            $next($job);

            return;
        }

        Log::channel('yak')->info('Task claim lost — another copy already owns it', [
            'job' => $job::class,
            'task_id' => $task->id,
            'expected_status' => $this->from->value,
        ]);

        if (method_exists($job, 'delete')) {
            $job->delete();
        }
    }
}
