<?php

namespace App\Jobs\Concerns;

use App\DataTransferObjects\TaskStop;
use App\Services\Tasks\StopNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the channel a task came from that the task failed.
 *
 * Inline `handleError()` methods move the task to Failed without the
 * queue's `failed()` hook ever running, so they call this after the
 * transition. System tasks have no requester to notify. A notification
 * that cannot be queued never masks the original failure.
 *
 * The consuming job must expose a `$task` property typed to YakTask.
 */
trait NotifiesSourceOfFailure
{
    private function notifySourceOfFailure(string $errorMessage, ?TaskStop $stop = null): void
    {
        try {
            StopNotifier::notify($this->task, $stop ?? TaskStop::yakSideProblem($errorMessage));
        } catch (Throwable $notifyError) {
            Log::channel('yak')->warning('Failed to record stop and notify', [
                'task_id' => $this->task->id,
                'error' => $notifyError->getMessage(),
            ]);
        }
    }
}
