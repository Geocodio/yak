<?php

namespace App\Support;

use App\Models\YakTask;

/**
 * Request/job-scoped pointer to the task currently being processed.
 * Used by RecordAiUsage to attribute AI SDK calls to the owning task.
 */
final class TaskContext
{
    private static ?int $taskId = null;

    public static function set(?YakTask $task): void
    {
        self::$taskId = $task?->id;
    }

    public static function clear(): void
    {
        self::$taskId = null;
    }

    public static function currentTaskId(): ?int
    {
        return self::$taskId;
    }

    /**
     * Run the callback with the given task set as the current context,
     * restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function run(YakTask $task, callable $callback): mixed
    {
        $previous = self::$taskId;
        self::$taskId = $task->id;

        try {
            return $callback();
        } finally {
            self::$taskId = $previous;
        }
    }
}
