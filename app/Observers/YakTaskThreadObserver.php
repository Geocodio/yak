<?php

namespace App\Observers;

use App\Enums\TaskMode;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;

/**
 * Gives every root work task its thread the moment it is created, so the
 * request has a page before its first run and while it waits for a
 * repository answer. A creator that passes `thread_id`, even as null, has
 * chosen the thread itself and is left alone.
 */
class YakTaskThreadObserver
{
    public function __construct(private readonly ThreadFactory $threads) {}

    public function created(YakTask $task): void
    {
        $isWorkTask = in_array($task->mode, [TaskMode::Fix, TaskMode::Research], true);

        $hasChosenThread = array_key_exists('thread_id', $task->getAttributes());

        if (! $isWorkTask || $task->parent_task_id !== null || $hasChosenThread) {
            return;
        }

        $persisted = YakTask::query()->withoutGlobalScopes()->findOrFail($task->id);

        $thread = $task->repo === Thread::UNKNOWN_REPOSITORY
            ? $this->threads->forRequestWithoutRepository($persisted)
            : $this->threads->forTask($persisted);

        $task->setAttribute('thread_id', $thread->id);
        $task->syncOriginalAttribute('thread_id');
    }
}
