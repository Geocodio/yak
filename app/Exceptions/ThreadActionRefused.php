<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A thread action that cannot run now, with a stable code the clients branch on.
 * Client APIs render it as `{error, message, details?}`; web controllers catch it and keep their own wording.
 */
final class ThreadActionRefused extends RuntimeException
{
    public const THREAD_CLOSED = 'thread_closed';

    public const THREAD_OWNED_BY_DEVICE = 'thread_owned_by_device';

    public const THREAD_BLOCKED = 'thread_blocked';

    public const TURN_RUNNING = 'turn_running';

    public const MESSAGE_DELIVERED = 'message_delivered';

    public const NOTHING_TO_CONTINUE = 'nothing_to_continue';

    public const NOTHING_TO_RESTORE = 'nothing_to_restore';

    public const QUESTION_CLOSED = 'question_closed';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [], public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function because(string $errorCode, string $message, array $details = []): self
    {
        return new self($errorCode, $message, $details);
    }
}
