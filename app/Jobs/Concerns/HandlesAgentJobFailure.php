<?php

namespace App\Jobs\Concerns;

use App\Enums\TaskStatus;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared `failed()` handler for jobs that own a YakTask and spawn a sandbox.
 *
 * When a queue worker's `--timeout` fires, Laravel throws a MaxAttempts /
 * timeout exception which lands here. Without this trait the task row
 * stays `running` forever and the Incus sandbox stays orphaned until
 * the hourly `yak:cleanup-sandboxes` sweeper notices.
 *
 * The consuming job must expose a `$task` property (public or readonly)
 * typed to YakTask. The sandbox container name is derived deterministically
 * from the task id so we can reap even if the job never stored the name.
 */
trait HandlesAgentJobFailure
{
    public function failed(?Throwable $e): void
    {
        $errorMessage = $e?->getMessage() ?? 'Job failed without exception';

        Log::channel('yak')->error(static::class . ' failed', [
            'task_id' => $this->task->id,
            'error' => $errorMessage,
            'exception_class' => $e !== null ? get_class($e) : null,
        ]);

        $task = $this->task->fresh();

        // Don't clobber a terminal status — the job may have succeeded
        // and only a post-success cleanup step failed, or a parallel path
        // (e.g. retry after CI) may already have finalised the task.
        if ($task === null) {
            return;
        }

        $terminal = [TaskStatus::Success, TaskStatus::Failed, TaskStatus::Expired];
        if (! in_array($task->status, $terminal, true)) {
            $task->update([
                'status' => TaskStatus::Failed,
                'error_log' => $errorMessage,
                'completed_at' => now(),
            ]);
        }

        // Reap the sandbox best-effort. The container name is derived from
        // the task id, so we can find it even though the original $containerName
        // local variable in handle() is gone.
        try {
            $sandbox = app(IncusSandboxManager::class);
            $containerName = $sandbox->containerName($task);
            if ($sandbox->containerExists($containerName)) {
                $sandbox->destroy($containerName);
            }
        } catch (Throwable $reapError) {
            Log::channel('yak')->warning('Sandbox reap after job failure failed', [
                'task_id' => $task->id,
                'error' => $reapError->getMessage(),
            ]);
        }
    }
}
