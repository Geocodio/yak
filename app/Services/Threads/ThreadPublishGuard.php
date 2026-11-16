<?php

namespace App\Services\Threads;

use App\Enums\ThreadVisibility;
use App\Models\Thread;
use Illuminate\Validation\ValidationException;

/**
 * Opening a pull request from a thread publishes its branch and a description
 * written from the conversation, so anything not visible to the whole team
 * needs an explicit acknowledgement first. A child follows its root.
 */
class ThreadPublishGuard
{
    public const string ACKNOWLEDGEMENT_KEY = 'acknowledged_private_publish';

    public const string MESSAGE = 'This thread is private. Opening a pull request publishes its branch and a description written from the private conversation to GitHub.';

    public static function requiresAcknowledgement(Thread $thread): bool
    {
        return $thread->effectiveVisibility() !== ThreadVisibility::Team;
    }

    /**
     * @throws ValidationException
     */
    public static function assertAcknowledged(Thread $thread, bool $acknowledged): void
    {
        if (self::requiresAcknowledgement($thread) && ! $acknowledged) {
            throw ValidationException::withMessages([self::ACKNOWLEDGEMENT_KEY => self::MESSAGE]);
        }
    }
}
