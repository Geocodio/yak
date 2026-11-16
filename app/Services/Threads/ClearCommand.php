<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Models\YakTask;

/**
 * `/clear` as a whole message ends the conversation's context without ending
 * the conversation: the task moves to a new thread that links back to the old
 * one, which stays open and unchanged.
 */
class ClearCommand
{
    public static function matches(string $text): bool
    {
        return trim($text) === '/clear';
    }

    public static function apply(Thread $previous, YakTask $task): Thread
    {
        return app(ThreadFactory::class)->continuing($previous, $task);
    }
}
