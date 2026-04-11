<?php

namespace App\Services;

use App\Models\TaskLog;
use App\Models\YakTask;
use Illuminate\Support\Facades\Log;

class TaskLogger
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function log(YakTask $task, string $message, string $level = 'info', ?array $metadata = null): TaskLog
    {
        Log::channel('yak')->{$level}($message, [
            'task_id' => $task->id,
            'repo' => $task->repo,
            'source' => $task->source,
            ...(is_array($metadata) ? $metadata : []),
        ]);

        return TaskLog::create([
            'yak_task_id' => $task->id,
            'level' => $level,
            'message' => $message,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function info(YakTask $task, string $message, ?array $metadata = null): TaskLog
    {
        return self::log($task, $message, 'info', $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function warning(YakTask $task, string $message, ?array $metadata = null): TaskLog
    {
        return self::log($task, $message, 'warning', $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function error(YakTask $task, string $message, ?array $metadata = null): TaskLog
    {
        return self::log($task, $message, 'error', $metadata);
    }
}
