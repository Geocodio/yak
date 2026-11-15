<?php

namespace App\Jobs\Push;

use App\Enums\LiveActivityState;
use App\Models\Thread;
use App\Services\Push\LiveActivities\LiveActivities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * The step update held back until its 15-second window ended: it sends the latest label with a fresh timestamp and opens the next window.
 */
class SendPendingStepUpdateJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $threadId)
    {
        $this->onQueue('default');
    }

    public function handle(LiveActivities $liveActivities): void
    {
        Cache::forget(LiveActivities::pendingKey($this->threadId));

        $label = Cache::get(LiveActivities::stepKey($this->threadId));
        $thread = Thread::withoutGlobalScopes()->find($this->threadId);

        if (! is_string($label) || $thread === null) {
            return;
        }

        Cache::put(LiveActivities::windowKey($thread), now()->getTimestamp() + LiveActivities::WINDOW_SECONDS, LiveActivities::WINDOW_SECONDS);

        $liveActivities->update($thread, LiveActivityState::Working, $label, 5);
    }
}
