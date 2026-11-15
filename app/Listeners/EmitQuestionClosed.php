<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Services\Threads\ThreadQuestionEvents;

/**
 * Emits `question {open: false}` when a head task leaves AwaitingClarification, whatever moved
 * it: an answer, the expiry, a cancel or a rerun.
 */
class EmitQuestionClosed
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        if ($event->from !== TaskStatus::AwaitingClarification || $event->to === TaskStatus::AwaitingClarification || $task->thread_id === null) {
            return;
        }

        if ($task->thread?->headTask()?->is($task) !== true) {
            return;
        }

        app(ThreadQuestionEvents::class)->closed($task);
    }
}
