<?php

namespace App\Services\Threads;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\ShortLockWait;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\Preview\ThreadPreviewRestore;

/**
 * Getting a thread going again: continue after a guard stop and retry a failed restore.
 * Shared by the web controllers and the app API.
 */
class ThreadRecovery
{
    public const DEVICE_SENTENCE = 'Your computer is working on this thread. Hand it back to Yak before continuing.';

    public const CLOSED_SENTENCE = 'This thread is closed.';

    /** Above the job's 900-second timeout, its release budget (20 x 30 seconds) and 300 seconds in the queue. */
    public const RESTORE_GUARD_SECONDS = 1800;

    public function __construct(private readonly ThreadTurns $turns) {}

    /**
     * A repeat while the continuation it started is still active answers with that run.
     *
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

        if ($lastRun?->prompt === 'Continue.' && $lastRun->getKey() === ThreadTurns::activeTurn($thread)?->getKey()) {
            return $lastRun;
        }

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

    public static function isRestoreQueued(Thread $thread): bool
    {
        return $thread->restore_requested_at !== null
            && $thread->restore_requested_at->greaterThan(now()->subSeconds(self::RESTORE_GUARD_SECONDS));
    }

    /**
     * False when a restore already runs or is queued and nothing was dispatched. The queued record is the
     * thread's `restore_requested_at`, set under the thread row lock, so repeated calls dispatch one job.
     *
     * @throws ThreadActionRefused
     */
    public function retryRestore(Thread $thread): bool
    {
        $job = ShortLockWait::threadTransaction(function () use ($thread): RetryThreadRestoreJob|bool {
            $locked = Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($thread->id);

            if (! $locked->isOpen()) {
                throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, self::CLOSED_SENTENCE);
            }

            if ($locked->owner_side === ThreadOwnerSide::Device) {
                throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, self::DEVICE_SENTENCE);
            }

            if ($locked->isSandboxBusy()) {
                return false;
            }

            if (self::isRestoreQueued($locked) || app(ThreadPreviewRestore::class)->isInFlight($locked)) {
                return false;
            }

            $needsRestore = $locked->blocked_reason === ThreadBlockReason::RestoreFailed
                || in_array($locked->sandbox_state, [ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed], true);

            if (! $needsRestore) {
                throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_RESTORE, 'Nothing needs restoring.');
            }

            $locked->forceFill(['restore_requested_at' => now()->startOfSecond()])->save();

            return new RetryThreadRestoreJob($locked, $locked->restore_requested_at);
        });

        if ($job === false) {
            return false;
        }

        dispatch($job);

        return true;
    }
}
