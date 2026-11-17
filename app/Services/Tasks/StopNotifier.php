<?php

namespace App\Services\Tasks;

use App\DataTransferObjects\TaskStop;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use Illuminate\Support\Facades\Log;

/**
 * Records why a task stopped and tells the people involved, verbatim.
 */
class StopNotifier
{
    public static function notify(YakTask $task, TaskStop $stop, NotificationType $type = NotificationType::Error): void
    {
        $task->recordStop($stop);

        if ($task->source === 'system') {
            return;
        }

        try {
            SendNotificationJob::dispatch($task, $type, $stop->summary, personalize: false, stop: $stop);
        } catch (\Throwable $e) {
            Log::channel('yak')->error('Stop notification dispatch failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
