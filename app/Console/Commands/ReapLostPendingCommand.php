<?php

namespace App\Console\Commands;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SetupYakJob;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('yak:reap-lost-pending {--minutes=10 : Age threshold for dispatched_at before a Pending task is considered lost}')]
#[Description('Re-dispatch Pending tasks whose queued job appears to have vanished')]
class ReapLostPendingCommand extends Command
{
    /**
     * On 2026-09-03, two Linear tasks sat Pending forever: `attempts = 0`,
     * no `started_at`, and their `RunYakJob` rows had vanished from the
     * `jobs` table with nothing blocking them. No existing sweep looks at
     * Pending tasks — `yak:reap-orphaned-tasks` scopes to Running only, by
     * design, since a task that's merely queued isn't "orphaned" in the
     * usual sense. This command covers that gap.
     *
     * A task qualifies when it's Pending, has never started
     * (`started_at IS NULL` — NOT `attempts = 0`, which would wrongly
     * exclude every retried or resumed task), and was dispatched more than
     * `--minutes` ago. `App\Services\AgentJobDispatcher` is the only thing
     * that stamps `dispatched_at`, and only for the four "claiming" agent
     * jobs (RunYakJob, ResearchYakJob, RunYakReviewJob, SetupYakJob) — see
     * its docblock. Tasks dispatched via RetryYakJob, RunFollowUpJob or
     * ClarificationReplyJob never get `dispatched_at` set at all, so this
     * query can never see them; re-dispatching one of those as a fresh
     * RunYakJob would run the wrong job and discard branch/session
     * context.
     *
     * Before assuming a candidate is actually lost, check the queue
     * itself via the stored `queue_job_uuid`: if a `jobs` row still
     * carries that uuid, the job is still queued or reserved by a worker
     * and re-dispatching would risk a duplicate run — change 0's atomic
     * claim makes that merely wasteful rather than destructive, but
     * skipping it is better. When no uuid was captured (a dispatch that
     * hit a `ShouldBeUnique` lock, or ran on the `sync` driver), fall back
     * to `dispatched_at` age alone, which the query already filtered on.
     *
     * Skips entirely while a deploy is draining or the shared Claude
     * session is unusable — those flags make every Pending task look
     * stale by the same threshold, and re-dispatching under either
     * condition would either be dispatched into a container about to be
     * recreated or held by HoldsForClaudeAuth anyway, so it's simplest
     * (and quietest) to no-op the whole sweep.
     */
    public function handle(AgentJobDispatcher $dispatcher): int
    {
        if (Cache::has(PausesDuringDrain::CACHE_KEY)) {
            $this->components->info('Drain in progress — skipping.');

            return self::SUCCESS;
        }

        if (Cache::has(ClaudeAuthCheck::UNUSABLE_CACHE_KEY)) {
            $this->components->info('Claude session unusable — skipping.');

            return self::SUCCESS;
        }

        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $candidates = YakTask::query()
            ->where('status', TaskStatus::Pending)
            ->whereNull('started_at')
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<', $threshold)
            ->get();

        $reDispatched = 0;

        foreach ($candidates as $task) {
            if ($this->stillQueued($task)) {
                continue;
            }

            $jobClass = $this->jobClassFor($task);

            TaskLogger::warning($task, 'Task self-healed — dispatched job appears lost', [
                'dispatched_at' => $task->dispatched_at?->toIso8601String(),
                'queue_job_uuid' => $task->queue_job_uuid,
                'redispatched_as' => $jobClass,
            ]);

            $dispatcher->dispatch($task, $jobClass);
            $reDispatched++;
        }

        $this->components->info("Re-dispatched {$reDispatched} lost pending task(s) (checked {$candidates->count()}).");

        return self::SUCCESS;
    }

    /**
     * Checks the `jobs` table for a row still carrying this task's queue
     * uuid — queued or reserved, either way still tracked by the queue.
     * The uuid lives inside the JSON `payload` column, not a dedicated
     * column, so this is a text search rather than an indexed lookup;
     * the `jobs` table is small enough in practice for that to be fine.
     *
     * A task with no `queue_job_uuid` (dispatch didn't go through the
     * queue at all, or lost a ShouldBeUnique race and never actually
     * pushed) has nothing to check here — the caller's `dispatched_at`
     * age filter is the only signal available for it.
     */
    private function stillQueued(YakTask $task): bool
    {
        $uuid = $task->queue_job_uuid;

        if ($uuid === null) {
            return false;
        }

        return DB::table('jobs')
            ->where('payload', 'like', '%"uuid":"' . $uuid . '"%')
            ->exists();
    }

    /**
     * @return class-string<RunYakJob|ResearchYakJob|RunYakReviewJob|SetupYakJob>
     */
    private function jobClassFor(YakTask $task): string
    {
        /** @var TaskMode $mode */
        $mode = $task->mode;

        return match ($mode) {
            TaskMode::Setup => SetupYakJob::class,
            TaskMode::Research => ResearchYakJob::class,
            TaskMode::Review => RunYakReviewJob::class,
            default => RunYakJob::class,
        };
    }
}
