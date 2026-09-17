<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Facades\Telemetry;

/**
 * Turns every status transition into a `task.status_changed` event, and
 * a terminal transition into `task.finished` carrying the end-to-end
 * numbers the funnel and latency charts read.
 */
class RecordTaskStatusChange
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        Telemetry::record('task.status_changed', [
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'mode' => $task->mode->value,
            'attempts' => (int) $task->attempts,
        ], task: $task);

        if (! in_array($event->to, [TaskStatus::Success, TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled], true)) {
            return;
        }

        $createdAt = $task->created_at;
        $completedAt = $task->completed_at ?? now();

        Telemetry::record('task.finished', [
            'status' => $event->to->value,
            'mode' => $task->mode->value,
            'attempts' => (int) $task->attempts,
            'has_pr' => $task->pr_url !== null,
            'is_follow_up' => $task->parent_task_id !== null,
            'cost_usd' => (float) $task->cost_usd,
            'num_turns' => (int) $task->num_turns,
            'agent_ms' => (int) $task->duration_ms,
            'queue_wait_ms' => $task->started_at !== null && $createdAt !== null
                ? max(0, $task->started_at->getTimestampMs() - $createdAt->getTimestampMs())
                : null,
        ], task: $task, durationMs: $createdAt !== null ? max(0, $completedAt->getTimestampMs() - $createdAt->getTimestampMs()) : null);
    }
}
