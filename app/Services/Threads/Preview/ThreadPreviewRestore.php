<?php

namespace App\Services\Threads\Preview;

use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\Threads\RestoreThreadPreviewJob;
use App\Models\Thread;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one rule for restoring a preview, shared by the web route and the app route.
 */
final class ThreadPreviewRestore
{
    public const DISPATCH_KEY_SECONDS = 300;

    /** The life of the sandbox lock: a step row that says running for longer belongs to a job that died. */
    public const RUNNING_STEP_SECONDS = 600;

    public function __construct(private ThreadPreviewStatus $status) {}

    /**
     * True while the sandbox is busy or a restore step is running. Database only.
     */
    public function isRunning(Thread $thread): bool
    {
        return $thread->isSandboxBusy()
            || $thread->restoreSteps()
                ->where('status', RestoreStepStatus::Running)
                ->where('started_at', '>=', now()->subSeconds(self::RUNNING_STEP_SECONDS))
                ->exists();
    }

    /**
     * Starts a preview restore of a destroyed sandbox, or retries a failed one whatever the sandbox state.
     * False when one already runs or was just dispatched; the result arrives through status and the `thread` event.
     *
     * @throws ThreadActionRefused
     */
    public function begin(Thread $thread): bool
    {
        $thread->refresh();

        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, 'This thread runs on a local machine.');
        }

        if ($this->isRunning($thread)) {
            return false;
        }

        $key = "thread-preview:restore:{$thread->id}";

        try {
            if (Cache::has($key)) {
                return false;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($thread->blocked_reason !== ThreadBlockReason::RestoreFailed && $this->currentState($thread) !== 'destroyed') {
            throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_RESTORE, 'The sandbox is still there, so there is nothing to restore.');
        }

        try {
            if (! Cache::add($key, true, self::DISPATCH_KEY_SECONDS)) {
                return false;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        RestoreThreadPreviewJob::dispatch($thread);

        return true;
    }

    /** The preview state; a cache that is down reads as unknown, so a sandbox that may still exist is never rebuilt. */
    private function currentState(Thread $thread): string
    {
        try {
            return $this->status->state($thread);
        } catch (Throwable $exception) {
            report($exception);

            return 'unknown';
        }
    }
}
