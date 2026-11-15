<?php

namespace App\Jobs;

use App\Enums\ThreadBlockReason;
use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rebuilds a removed sandbox or resumes a failed restore, only while one is still needed. It carries the
 * `restore_requested_at` it was dispatched with and clears that guard only while it is still its own.
 */
class RetryThreadRestoreJob implements ShouldQueue
{
    use Queueable;

    public const RELEASE_LIMIT = 20;

    public int $timeout = 900;

    public int $tries = 21;

    public int $maxExceptions = 1;

    public function __construct(public readonly Thread $thread, public readonly ?CarbonImmutable $requestedAt = null)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(ThreadSandbox $sandbox): void
    {
        try {
            $wake = $sandbox->restoreIfNeeded($this->thread);
            $canWait = $this->attempts() <= self::RELEASE_LIMIT;

            if ($wake === null && $canWait) {
                $this->release(15);

                return;
            }

            $current = $wake !== null && ! $wake->ready ? Thread::query()->withoutGlobalScopes()->find($this->thread->id) : null;

            if ($current?->blocked_reason === ThreadBlockReason::WaitingForSandbox) {
                if ($canWait) {
                    $this->release(30);

                    return;
                }

                $current->update(['blocked_reason' => null, 'blocked_at' => null]);
            }

            $this->clearGuard();
        } catch (Throwable $exception) {
            $this->clearGuard();

            throw $exception;
        }
    }

    private function clearGuard(): void
    {
        if ($this->requestedAt === null) {
            return;
        }

        Thread::query()->withoutGlobalScopes()->whereKey($this->thread->id)
            ->where('restore_requested_at', $this->requestedAt)
            ->update(['restore_requested_at' => null]);
    }
}
