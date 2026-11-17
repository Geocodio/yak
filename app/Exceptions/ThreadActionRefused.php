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

    public const NO_REPOSITORY = 'no_repository';

    public const NO_REPOSITORY_SENTENCE = 'Pick a repository first.';

    public const THREAD_OWNED_BY_DEVICE = 'thread_owned_by_device';

    public const THREAD_BLOCKED = 'thread_blocked';

    public const TURN_RUNNING = 'turn_running';

    public const MESSAGE_DELIVERED = 'message_delivered';

    public const NOTHING_TO_CONTINUE = 'nothing_to_continue';

    public const NOTHING_TO_RESTORE = 'nothing_to_restore';

    public const NOT_YOUR_MESSAGE = 'not_your_message';

    public const QUESTION_CLOSED = 'question_closed';

    public const SANDBOX_DESTROYED = 'sandbox_destroyed';

    public const NO_SANDBOX = 'no_sandbox';

    public const ORIGIN_NOT_ALLOWED = 'origin_not_allowed';

    public const SANDBOX_UNAVAILABLE = 'sandbox_unavailable';

    public const FILE_CONFLICT = 'file_conflict';

    public const SUB_THREAD_CANNOT_SPAWN = 'sub_thread_cannot_spawn';

    public const STACK_STOPPED = 'stack_stopped';

    public const STACK_FULL = 'stack_full';

    public const CHILD_NOT_FAILED = 'child_not_failed';

    public const NOTHING_TO_RETRY = 'nothing_to_retry';

    public const NOTHING_TO_PUBLISH = 'nothing_to_publish';

    public const CI_NOT_FAILING = 'ci_not_failing';

    public const TASK_NOT_RETRYABLE = 'task_not_retryable';

    public const TASK_NOT_CANCELLABLE = 'task_not_cancellable';

    public const NOT_A_REVIEW = 'not_a_review';

    public const REVIEW_ALREADY_QUEUED = 'review_already_queued';

    public const PULL_REQUEST_UNKNOWN = 'pull_request_unknown';

    public const GITHUB_UNAVAILABLE = 'github_unavailable';

    public const NOTHING_TO_RENDER = 'nothing_to_render';

    public const TASK_NOT_REROUTABLE = 'task_not_reroutable';

    public const REPOSITORY_ALREADY_PICKED = 'repository_already_picked';

    public const REPOSITORY_NOT_OFFERED = 'repository_not_offered';

    public const REVIEW_NOT_REQUESTED = 'review_not_requested';

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
