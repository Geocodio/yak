<?php

namespace App\Jobs\Concerns;

use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Waits out a usage limit without failing the task. A released job keeps the
 * retryUntil it was first queued with, so a hold that reaches past that
 * deadline goes back on the queue as a fresh copy with a new deadline.
 *
 * The consuming job must use InteractsWithQueue and expose a `$task` property.
 */
trait HoldsForUsageLimit
{
    protected function holdForUsageLimit(int $seconds): void
    {
        $retryUntil = $this->job?->payload()['retryUntil'] ?? null;

        if ($retryUntil === null || now()->addSeconds($seconds + 300)->getTimestamp() <= $retryUntil) {
            $this->release($seconds);

            return;
        }

        // The unique lock is still held by this attempt, so dispatch through the bus rather than PendingDispatch.
        app(Dispatcher::class)->dispatch((new static($this->task))->delay($seconds));
        $this->delete();
    }
}
