<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

#[Signature('yak:drain {--wait=300 : Minimum seconds to wait for a silent-but-in-flight task before forcing failure} {--max-wait=2700 : Hard ceiling in seconds — every in-flight task is force-failed once this is reached, regardless of activity} {--poll=5 : Polling interval in seconds}')]
#[Description('Pause new task pickups and wait for in-flight tasks to finish before a container recreate')]
class DrainForDeployCommand extends Command
{
    /**
     * Silence window used to decide whether an in-flight task is still
     * demonstrably alive. Matches yak:reap-orphaned-tasks so the two
     * sweeps agree on what "stalled" means — a long tool call (a full
     * test suite, a docker-compose warmup) can legitimately emit
     * nothing for 10+ minutes (see RunYakJob's class docblock), so a
     * shorter window would force-fail healthy tasks.
     */
    private const SILENCE_MINUTES = 15;

    private const CLAUDE_QUEUE = 'yak-claude';

    /**
     * Bounded grace period, after all tasks have settled, for straggler
     * queue workers to release their reserved job row. A row that
     * survives the container recreate is redelivered `retry_after`
     * seconds later (config/queue.php) against a task that may have
     * moved on in the meantime. Change 0's atomic claim makes that
     * non-destructive, but not hitting it at all is better.
     */
    private const WORKER_EXIT_WAIT_SECONDS = 30;

    /**
     * Called by Ansible before a container recreate. Sets the drain
     * cache flag (read by PausesDuringDrain middleware) so queue
     * workers stop picking up new agent jobs, then polls for in-flight
     * tasks to finish.
     *
     * "In-flight" is `Running` **or** `Retrying` — a task set back to
     * Retrying by ProcessCIResultJob is running a full agent session
     * via RetryYakJob and never sets Running again, so a drain that
     * only looked at Running would miss it entirely and report
     * "nothing to drain" while a live retry is destroyed underneath it.
     *
     * The wait is adaptive rather than a fixed budget: a task is only
     * forced to Failed once it has gone quiet (no task_log activity
     * within the silence window) AND at least --wait seconds have
     * elapsed. A task that keeps logging is left alone until --max-wait,
     * a hard ceiling that forces everything regardless of activity.
     *
     * AwaitingCi / AwaitingClarification tasks don't hold a worker and
     * are left alone — they'll resume polling under the new container.
     */
    public function handle(IncusSandboxManager $sandbox): int
    {
        $waitSeconds = (int) $this->option('wait');
        $maxWaitSeconds = (int) $this->option('max-wait');
        $pollSeconds = max(1, (int) $this->option('poll'));

        // TTL is derived from --max-wait, not --wait: the ceiling is the
        // longest this command can legitimately still be waiting, so the
        // flag must outlast it. Deriving it from --wait would let the
        // flag expire mid-drain (e.g. wait+600=900s against a 2700s
        // ceiling), letting workers resume pickups into a container
        // that's about to be recreated.
        Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addSeconds($maxWaitSeconds + 600));

        $this->components->info(
            "Drain flag set — workers will pause new pickups. Waiting up to {$maxWaitSeconds}s total " .
            "(minimum {$waitSeconds}s patience for a silent task, " . self::SILENCE_MINUTES . ' min silence window).'
        );

        $elapsed = 0;
        while (true) {
            $inFlight = YakTask::whereIn('status', [TaskStatus::Running, TaskStatus::Retrying])->get();

            if ($inFlight->isEmpty()) {
                $this->components->info('No in-flight tasks — drain complete.');

                break;
            }

            $ceilingReached = $elapsed >= $maxWaitSeconds;
            $silenceThreshold = now()->subMinutes(self::SILENCE_MINUTES);

            $remaining = $this->settleStragglers($inFlight, $sandbox, $ceilingReached, $elapsed, $waitSeconds, $maxWaitSeconds, $silenceThreshold);

            if ($remaining->isEmpty()) {
                break;
            }

            $this->reportProgress($remaining, $silenceThreshold, $elapsed, $waitSeconds, $maxWaitSeconds);

            Sleep::for($pollSeconds)->seconds();
            $elapsed += $pollSeconds;
        }

        $this->waitForStragglerWorkers($pollSeconds);

        return self::SUCCESS;
    }

    /**
     * Force-fail whichever of the in-flight tasks are past the point
     * they're allowed to keep running, returning the ones still owed
     * more patience.
     *
     * @param  Collection<int, YakTask>  $inFlight
     * @return Collection<int, YakTask>
     */
    private function settleStragglers(
        Collection $inFlight,
        IncusSandboxManager $sandbox,
        bool $ceilingReached,
        int $elapsed,
        int $waitSeconds,
        int $maxWaitSeconds,
        CarbonInterface $silenceThreshold,
    ): Collection {
        $remaining = collect();

        foreach ($inFlight as $task) {
            if ($ceilingReached) {
                $this->failStraggler(
                    $task,
                    $sandbox,
                    "Deploy interrupted the task after hitting the {$maxWaitSeconds}s drain ceiling. Retry it once the deploy is done.",
                    ['max_wait_seconds' => $maxWaitSeconds],
                );

                continue;
            }

            $silent = $this->isSilent($task, $silenceThreshold);

            if ($elapsed >= $waitSeconds && $silent) {
                $this->failStraggler(
                    $task,
                    $sandbox,
                    "Deploy interrupted the task after {$waitSeconds}s with no activity. Retry it once the deploy is done.",
                    ['wait_seconds' => $waitSeconds, 'silence_minutes' => self::SILENCE_MINUTES],
                );

                continue;
            }

            $remaining->push($task);
        }

        return $remaining;
    }

    /**
     * @param  Collection<int, YakTask>  $remaining
     */
    private function reportProgress(Collection $remaining, CarbonInterface $silenceThreshold, int $elapsed, int $waitSeconds, int $maxWaitSeconds): void
    {
        $alive = $remaining->filter(fn (YakTask $task) => ! $this->isSilent($task, $silenceThreshold))->count();
        $silent = $remaining->count() - $alive;

        $this->components->info(
            "Waiting on {$remaining->count()} in-flight task(s) — {$alive} actively logging, {$silent} silent. " .
            "({$elapsed}s elapsed, min patience {$waitSeconds}s, hard ceiling {$maxWaitSeconds}s)"
        );
    }

    private function isSilent(YakTask $task, CarbonInterface $silenceThreshold): bool
    {
        $latestLog = $task->logs()->latest('created_at')->first();

        return $latestLog === null || $latestLog->created_at < $silenceThreshold;
    }

    /**
     * Give straggler yak-claude queue workers a bounded chance to
     * finish releasing their reserved job row before returning control
     * to Ansible. This narrows, but cannot close, the race between "a
     * worker just picked up a job" and the container recreate that
     * follows — Change 0's atomic claim makes a redelivered row
     * non-destructive if we run out of patience here.
     */
    private function waitForStragglerWorkers(int $pollSeconds): void
    {
        $elapsed = 0;

        while ($elapsed < self::WORKER_EXIT_WAIT_SECONDS) {
            $reserved = $this->reservedWorkerCount();

            if ($reserved === 0) {
                return;
            }

            $this->components->info(
                "Waiting for {$reserved} reserved " . self::CLAUDE_QUEUE . ' job(s) to release... ' .
                "({$elapsed}s / " . self::WORKER_EXIT_WAIT_SECONDS . 's)'
            );

            Sleep::for($pollSeconds)->seconds();
            $elapsed += $pollSeconds;
        }

        $remaining = $this->reservedWorkerCount();

        if ($remaining > 0) {
            $this->components->warn(
                "{$remaining} reserved " . self::CLAUDE_QUEUE . ' job(s) still outstanding after ' .
                self::WORKER_EXIT_WAIT_SECONDS . 's — proceeding with the recreate anyway.'
            );
        }
    }

    private function reservedWorkerCount(): int
    {
        return (int) DB::table('jobs')
            ->where('queue', self::CLAUDE_QUEUE)
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failStraggler(YakTask $task, IncusSandboxManager $sandbox, string $reason, array $context = []): void
    {
        TaskLogger::warning($task, 'Task interrupted by deploy drain', $context);

        $task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $reason,
            'completed_at' => now(),
        ]);

        try {
            $containerName = $sandbox->containerName($task);
            if ($sandbox->containerExists($containerName)) {
                $sandbox->destroy($containerName);
            }
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during drain', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($task->source !== 'system') {
            try {
                SendNotificationJob::dispatch($task, NotificationType::Error, $reason);
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Failed to dispatch drain notification', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
