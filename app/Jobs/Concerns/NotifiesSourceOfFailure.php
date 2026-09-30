<?php

namespace App\Jobs\Concerns;

use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
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
    private function notifySourceOfFailure(string $errorMessage): void
    {
        if ($this->task->source === 'system') {
            return;
        }

        try {
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $errorMessage);
        } catch (Throwable $dispatchError) {
            Log::channel('yak')->warning('Failed to dispatch failure notification', [
                'task_id' => $this->task->id,
                'error' => $dispatchError->getMessage(),
            ]);
        }
    }
}
