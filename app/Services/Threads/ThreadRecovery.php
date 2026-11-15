<?php

namespace App\Services\Threads;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Teleport\ThreadNotOwnedByYak;

/**
 * Getting a thread going again: continue after a guard stop and retry a failed restore.
 * Shared by the web controllers and the app API.
 */
class ThreadRecovery
{
    public const DEVICE_SENTENCE = 'Your computer is working on this thread. Hand it back to Yak before continuing.';

    public const CLOSED_SENTENCE = 'This thread is closed.';

    public function __construct(private readonly ThreadTurns $turns) {}

    /**
     * @throws ThreadActionRefused
     */
    public function continueTurn(Thread $thread, User $user): TaskRun
    {
        if ($thread->owner_side === ThreadOwnerSide::Device) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, self::DEVICE_SENTENCE);
        }

        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, self::CLOSED_SENTENCE);
        }

        $lastRun = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();
        $head = $thread->headTask();

        if ($head === null || $lastRun?->getAttribute('turn_state') !== TurnState::StoppedByGuard) {
            throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_CONTINUE, 'There is nothing to continue.');
        }

        try {
            return $this->turns->start($head, 'Continue.', $user, null);
        } catch (ThreadBusy) {
            throw ThreadActionRefused::because(ThreadActionRefused::TURN_RUNNING, 'This thread is already working. Give it a moment.');
        } catch (ThreadNotOwnedByYak) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, self::DEVICE_SENTENCE);
        }
    }

    /**
     * False when a restore already runs and nothing was dispatched.
     *
     * @throws ThreadActionRefused
     */
    public function retryRestore(Thread $thread): bool
    {
        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, self::CLOSED_SENTENCE);
        }

        if ($thread->isSandboxBusy()) {
            return false;
        }

        if ($thread->blocked_reason !== ThreadBlockReason::RestoreFailed) {
            throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_RESTORE, 'Nothing needs restoring.');
        }

        RetryThreadRestoreJob::dispatch($thread);

        return true;
    }
}
