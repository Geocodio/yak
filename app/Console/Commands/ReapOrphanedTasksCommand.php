<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
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
        $errorMessage = "Worker crashed mid-run — no activity for {$minutes}+ minutes. Task marked failed and sandbox reaped.";

        TaskLogger::warning($task, 'Task reaped as orphaned', [
            'threshold_minutes' => $minutes,
        ]);

        $task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        // Best-effort sandbox teardown.
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

        if ($task->source !== 'system') {
            try {
                SendNotificationJob::dispatch($task, NotificationType::Error, $errorMessage);
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Failed to dispatch orphan notification', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
