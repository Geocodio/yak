<?php

namespace App\Console\Commands;

use App\DataTransferObjects\TaskStop;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\Tasks\StopNotifier;
use App\Services\Threads\ThreadLiveQuestions;
use App\Services\Threads\ThreadTurns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('yak:reap-orphaned-tasks {--minutes=15 : Silence threshold before a Running or Retrying task is considered orphaned}')]
#[Description('Finalise tasks stuck in Running or Retrying whose queue worker crashed without calling failed()')]
class ReapOrphanedTasksCommand extends Command
{
    /**
     * A worker can die without triggering Laravel's failed() hook —
     * an OOM kill, a container restart, a supervisord-initiated stop
     * during deploy, etc. When that happens the task row stays
     * `running` (or `retrying`), the Incus sandbox stays alive, and
     * the existing yak:cleanup-sandboxes sweep (which only touches
     * containers for terminal tasks) doesn't help.
     *
     * This command finds Running or Retrying tasks whose latest
     * task_log is older than the threshold, marks them Failed with a
     * helpful error, destroys the sandbox, and notifies the source
     * channel via the standard failure path.
     *
     * `Running` and `Retrying` are both in scope:
     *   - Retrying is set by ProcessCIResultJob before dispatching
     *     RetryYakJob, which runs a full agent session and never sets
     *     Running again. It is not a brief transitional state — a
     *     worker crash mid-retry leaves the task stuck exactly like a
     *     crash mid-Running would, with no other sweep covering it.
     *   - AwaitingCi has its own yak:timeout-ci command
     *   - AwaitingClarification has its own yak:cleanup-expired-clarifications
     *   - Pending tasks are just queued, not orphaned
     *   - A task whose turn waits on a live question is quiet until the
     *     person answers; the question's own wait limit ends that turn
     *
     * A thread task's turn is stopped too, so the runner is interrupted
     * and the thread is free for its next message.
     */
    public function handle(IncusSandboxManager $sandbox): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $candidates = YakTask::query()
            ->whereIn('status', [TaskStatus::Running, TaskStatus::Retrying])
            ->where('updated_at', '<', $threshold)
            ->get();

        $reaped = 0;
        foreach ($candidates as $task) {
            if (ThreadLiveQuestions::isOpen($task)) {
                continue;
            }

            $latestLog = $task->logs()->latest('created_at')->first();

            if ($latestLog !== null && $latestLog->created_at >= $threshold) {
                // Worker is still producing output — not orphaned.
                continue;
            }

            $this->reap($task, $sandbox, $minutes);
            $reaped++;
        }

        $this->components->info("Reaped {$reaped} orphaned task(s)");

        return self::SUCCESS;
    }

    private function reap(YakTask $task, IncusSandboxManager $sandbox, int $minutes): void
    {
        $errorMessage = "Worker crashed mid-run. No activity for {$minutes}+ minutes, so the task was marked failed.";

        TaskLogger::warning($task, 'Task reaped as orphaned', [
            'threshold_minutes' => $minutes,
        ]);

        $task->update([
            'status' => TaskStatus::Failed,
            'completed_at' => now(),
        ]);

        Repository::markSetupFailedFor($task);

        if ($task->thread !== null) {
            $this->stopThreadTurn($task);
        } else {
            $this->destroyTaskSandbox($task, $sandbox);
        }

        StopNotifier::notify($task, TaskStop::yakSideProblem($errorMessage));
    }

    /**
     * The thread keeps its sandbox for the next message; only the stuck turn ends.
     */
    private function stopThreadTurn(YakTask $task): void
    {
        try {
            app(ThreadTurns::class)->stop($task->thread);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Stopping the thread turn failed during reap', [
                'task_id' => $task->id,
                'thread_id' => $task->thread_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function destroyTaskSandbox(YakTask $task, IncusSandboxManager $sandbox): void
    {
        try {
            $containerName = $sandbox->containerName($task);
            if ($sandbox->containerExists($containerName)) {
                $sandbox->destroy($containerName);
            }
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during reap', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
