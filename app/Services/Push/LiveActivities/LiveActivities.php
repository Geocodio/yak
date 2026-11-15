<?php

namespace App\Services\Push\LiveActivities;

use App\Enums\LiveActivityState;
use App\Enums\ThreadBlockReason;
use App\Jobs\Push\BroadcastLiveActivityJob;
use App\Jobs\Push\SendPendingStepUpdateJob;
use App\Models\DeviceLiveActivity;
use App\Models\Thread;
use App\Services\Push\Apns\ApnsConfig;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one place that decides what each thread moment sends to a thread's broadcast channel.
 */
class LiveActivities
{
    public const WINDOW_SECONDS = 15;

    public function __construct(private readonly ApnsConfig $config) {}

    /**
     * A step update goes out at once, or once at the end of the current 15-second window with the latest label.
     * Only the cache is touched here, so the thread driver never waits on this path.
     */
    public function stepChanged(Thread $thread, string $label): void
    {
        if (! $this->canBroadcast($thread)) {
            return;
        }

        try {
            if (Cache::get(self::stepKey($thread)) === $label) {
                return;
            }

            Cache::put(self::stepKey($thread), $label, 3600);

            $windowEnd = now()->getTimestamp() + self::WINDOW_SECONDS;

            if (Cache::add(self::windowKey($thread), $windowEnd, self::WINDOW_SECONDS)) {
                $this->update($thread, LiveActivityState::Working, $label, 5);

                return;
            }

            if (Cache::add(self::pendingKey($thread), true, self::WINDOW_SECONDS)) {
                $end = (int) Cache::get(self::windowKey($thread), $windowEnd);

                SendPendingStepUpdateJob::dispatch($thread->id)->delay(now()->setTimestamp($end));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function update(Thread $thread, LiveActivityState $state, string $step, int $priority): void
    {
        if (! $this->canBroadcast($thread)) {
            return;
        }

        $fresh = $this->fresh($thread);

        BroadcastLiveActivityJob::dispatch($thread->id, 'update', LiveActivityContent::for($fresh, $state, $step), now()->getTimestamp(), $priority);
    }

    /**
     * The rows go even when nothing can be sent, so a leftover row never blocks the next turn's start.
     */
    public function end(Thread $thread, LiveActivityState $state): void
    {
        DeviceLiveActivity::query()->where('thread_id', $thread->id)->delete();

        try {
            Cache::forget(self::stepKey($thread));
            Cache::forget(self::windowKey($thread));
            Cache::forget(self::pendingKey($thread));
        } catch (Throwable $exception) {
            report($exception);
        }

        if (! $this->canBroadcast($thread)) {
            return;
        }

        $fresh = $this->fresh($thread);

        BroadcastLiveActivityJob::dispatch($thread->id, 'end', LiveActivityContent::for($fresh, $state, ''), now()->getTimestamp(), 10);
    }

    public function blocked(Thread $thread, ThreadBlockReason $reason): void
    {
        $label = LiveActivitySteps::forWaiting($reason);

        if ($label !== null) {
            $this->update($thread, LiveActivityState::Working, $label, 10);
        } elseif (LiveActivitySteps::needsPerson($reason)) {
            $this->end($thread, LiveActivityState::NeedsYou);
        }
    }

    public function questionOpened(Thread $thread): void
    {
        $this->end($thread, LiveActivityState::NeedsYou);
    }

    public static function stepKey(Thread|int $thread): string
    {
        return 'live-activity:step:' . self::id($thread);
    }

    public static function windowKey(Thread|int $thread): string
    {
        return 'live-activity:window:' . self::id($thread);
    }

    public static function pendingKey(Thread|int $thread): string
    {
        return 'live-activity:pending:' . self::id($thread);
    }

    private static function id(Thread|int $thread): int
    {
        return $thread instanceof Thread ? $thread->id : $thread;
    }

    private function canBroadcast(Thread $thread): bool
    {
        return $thread->live_activity_channel !== null && $this->config->isConfigured();
    }

    /**
     * The model the driver holds can be older than a visibility change made mid-turn.
     */
    private function fresh(Thread $thread): Thread
    {
        return Thread::withoutGlobalScopes()->find($thread->id) ?? $thread;
    }
}
