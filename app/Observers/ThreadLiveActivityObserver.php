<?php

namespace App\Observers;

use App\Models\Thread;
use App\Services\Push\LiveActivities\LiveActivities;
use Throwable;

/**
 * Shows a waiting state on the Live Activity, or ends it when only a person can unblock the thread.
 */
class ThreadLiveActivityObserver
{
    public function __construct(private readonly LiveActivities $liveActivities) {}

    public function updated(Thread $thread): void
    {
        $reason = $thread->blocked_reason;

        if ($reason === null || ! $thread->wasChanged('blocked_reason')) {
            return;
        }

        try {
            $this->liveActivities->blocked($thread, $reason);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
