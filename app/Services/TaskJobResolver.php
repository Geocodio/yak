<?php

namespace App\Services;

use App\Enums\TaskMode;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Models\YakTask;

class TaskJobResolver
{
    /**
     * @return class-string
     */
    public static function jobClass(YakTask $task): string
    {
        return match ($task->mode) {
            TaskMode::Review => RunYakReviewJob::class,
            default => RunYakJob::class,
        };
    }

    public static function dispatch(YakTask $task): void
    {
        $class = self::jobClass($task);
        $class::dispatch($task);
    }
}
