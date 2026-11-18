<?php

namespace App\Services\CiFailure;

use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a failed ci-failure task is worth telling a person about:
 * the first failure of a claim, or a failure whose outcome differs from the
 * previous one. Infrastructure failures never notify. RecordCiFailureOutcome
 * records the verdict when it updates the claims, because by the time the
 * notification job runs the claims already hold the new outcome.
 */
class CiFailureNotificationGate
{
    public static function remember(YakTask $task, bool $shouldNotify): void
    {
        Cache::put(self::key($task), $shouldNotify, now()->addDay());
    }

    public static function forget(YakTask $task): void
    {
        Cache::forget(self::key($task));
    }

    public static function shouldNotify(YakTask $task): bool
    {
        return (bool) Cache::get(self::key($task), true);
    }

    private static function key(YakTask $task): string
    {
        return "ci-failure-notify:{$task->id}";
    }
}
