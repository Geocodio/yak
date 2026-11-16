<?php

namespace App\Services\Ssh;

use App\Models\Thread;
use OutOfRangeException;

/**
 * The loopback address the wake daemon listens on for a thread's sandbox. Derived from the thread id, so there is no
 * column and no registry: 127.<16 + id >> 16>.<id >> 8 & 255>.<id & 255>.
 */
final class SandboxSshAddress
{
    public const MAX_THREAD_ID = 4_194_303;

    public static function for(Thread $thread): string
    {
        $id = (int) $thread->id;

        if ($id < 1 || $id > self::MAX_THREAD_ID) {
            throw new OutOfRangeException("Thread id {$id} has no sandbox ssh address.");
        }

        return sprintf('127.%d.%d.%d', 16 + ($id >> 16), ($id >> 8) & 255, $id & 255);
    }

    public static function threadFor(string $address): ?Thread
    {
        if (preg_match('/^127\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $address, $parts) !== 1) {
            return null;
        }

        [, $high, $middle, $low] = array_map('intval', $parts);

        if ($high < 16 || $high > 79 || $middle > 255 || $low > 255) {
            return null;
        }

        $id = (($high - 16) << 16) | ($middle << 8) | $low;

        return $id < 1 ? null : Thread::withoutGlobalScopes()->find($id);
    }
}
