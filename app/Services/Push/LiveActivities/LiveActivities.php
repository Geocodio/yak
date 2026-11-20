<?php

namespace App\Services\Push\LiveActivities;

use App\Enums\LiveActivityState;
use App\Enums\PushTokenKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Jobs\Push\BroadcastLiveActivityJob;
use App\Jobs\Push\SendPendingStepUpdateJob;
use App\Jobs\Push\StartLiveActivityJob;
use App\Models\DeviceLiveActivity;
use App\Models\DevicePushToken;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadAgents;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one place that decides what each thread moment sends to a thread's broadcast channel.
 */
class LiveActivities
{
    public const WINDOW_SECONDS = 15;

    /** A phone shows at most this many activities, counted over the last {@see self::ACTIVITY_WINDOW_HOURS} hours. */
    public const MAX_ACTIVITIES_PER_PHONE = 3;

    public const ACTIVITY_WINDOW_HOURS = 8;

    public function __construct(private readonly ApnsConfig $config, private readonly LiveActivityChannels $channels) {}

    /**
     * Starts an activity on each phone of the person driving a turn that began elsewhere (the web).
     * A person who sends from the phone may get this remote start and the app's own start for the same thread, because the app has not reported its activity yet; the app ends the duplicate (`LiveActivityPlanner.reconcile` in app plan 3, which also keeps a phone at three when several instances start at once), and the server does not try to detect it.
     * Only root threads on Yak start one: a stack's sub-threads have drivers too and would use up the three slots.
     */
    public function turnStarted(TaskRun $run): void
    {
        if (! $this->config->isConfigured() || $run->driver_user_id === null || $run->thread_id === null) {
            return;
        }

        $thread = Thread::withoutGlobalScopes()->find($run->thread_id);
        $driver = User::query()->find($run->driver_user_id);

        if ($thread === null || $driver === null || ! $thread->isOpen() || $thread->owner_side === ThreadOwnerSide::Device || ! $thread->isRoot()) {
            return;
        }

        if (! app(ThreadAccess::class)->canSee($driver, $thread)) {
            return;
        }

        $hadChannel = filled($thread->live_activity_channel);
        $cutoff = now()->subHours(self::ACTIVITY_WINDOW_HOURS);

        $tokens = DevicePushToken::query()
            ->deliverable()
            ->where('kind', PushTokenKind::LiveActivityStart->value)
            ->whereHas('device', fn ($device) => $device->where('user_id', $driver->id))
            ->get();

        foreach ($tokens as $token) {
            $recent = DeviceLiveActivity::query()->where('device_id', $token->device_id)->where('started_at', '>=', $cutoff);

            if ((clone $recent)->where('thread_id', $thread->id)->exists() || (clone $recent)->count() >= self::MAX_ACTIVITIES_PER_PHONE) {
                continue;
            }

            DeviceLiveActivity::query()->where('device_id', $token->device_id)->where('thread_id', $thread->id)->delete();

            $written = DeviceLiveActivity::query()->insertOrIgnore([
                'device_id' => $token->device_id,
                'thread_id' => $thread->id,
                'source' => 'server',
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($written === 1) {
                $row = DeviceLiveActivity::query()->where('device_id', $token->device_id)->where('thread_id', $thread->id)->firstOrFail();
                StartLiveActivityJob::dispatch($row->id, $token->id, $run->id);
            }
        }

        if ($hadChannel) {
            $this->update($thread, LiveActivityState::Working, LiveActivitySteps::STARTING, 10);
        }
    }

    /**
     * Ends the activity of a turn that completed, after the finisher has parked any question. A turn that failed already sent its own end, so a later "finished" never overwrites it.
     */
    public function turnSettled(TaskRun $run): void
    {
        $run = $run->fresh();

        if ($run === null || $run->turn_state !== TurnState::Completed || $run->thread_id === null || ThreadAgents::isQuietTurn($run)) {
            return;
        }

        $thread = Thread::withoutGlobalScopes()->find($run->thread_id);

        if ($thread === null) {
            return;
        }

        $state = $this->settledState($thread);

        // A parked question already sent this turn's needs-you end.
        if ($state === LiveActivityState::NeedsYou && $this->pullQuestionEnd($thread, $run)) {
            return;
        }

        $this->end($thread, $state);
    }

    public function turnFailed(TaskRun $run): void
    {
        $thread = $run->thread_id === null ? null : Thread::withoutGlobalScopes()->find($run->thread_id);

        if ($thread === null) {
            return;
        }

        $this->end($thread, in_array($run->turn_state, [TurnState::StoppedByGuard, TurnState::Interrupted], true) ? LiveActivityState::Stopped : LiveActivityState::Failed);
    }

    /**
     * Ends the activity with the state of the latest turn, then deletes the channel at Apple.
     * The delete is chained behind the end, so a retried end still finds its channel.
     */
    public function threadClosed(Thread $thread): void
    {
        $run = $thread->runs()->latest('id')->first();

        $state = match ($run?->turn_state) {
            null => LiveActivityState::Finished,
            TurnState::Completed => $this->settledState($thread),
            TurnState::Failed => LiveActivityState::Failed,
            default => LiveActivityState::Stopped,
        };

        $endJob = $this->prepareEnd($thread, $state);
        $deleteJob = $this->channels->deleteJobFor($thread);

        if ($endJob !== null && $deleteJob !== null) {
            Bus::chain([$endJob, $deleteJob])->dispatch();

            return;
        }

        if ($endJob !== null) {
            dispatch($endJob);
        }

        if ($deleteJob !== null) {
            dispatch($deleteJob);
        }
    }

    private function settledState(Thread $thread): LiveActivityState
    {
        $needsPerson = $thread->headTask()?->status === TaskStatus::AwaitingClarification
            || ($thread->blocked_reason !== null && LiveActivitySteps::needsPerson($thread->blocked_reason));

        return $needsPerson ? LiveActivityState::NeedsYou : LiveActivityState::Finished;
    }

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
        $job = $this->prepareEnd($thread, $state);

        if ($job !== null) {
            dispatch($job);
        }
    }

    private function prepareEnd(Thread $thread, LiveActivityState $state): ?BroadcastLiveActivityJob
    {
        DeviceLiveActivity::query()->where('thread_id', $thread->id)->delete();

        $this->forgetPendingStep($thread);

        try {
            Cache::forget(self::windowKey($thread));
        } catch (Throwable $exception) {
            report($exception);
        }

        if (! $this->canBroadcast($thread)) {
            return null;
        }

        return new BroadcastLiveActivityJob($thread->id, 'end', LiveActivityContent::for($this->fresh($thread), $state, ''), now()->getTimestamp(), 10);
    }

    public function blocked(Thread $thread, ThreadBlockReason $reason): void
    {
        $label = LiveActivitySteps::forWaiting($reason);

        if ($label !== null) {
            $this->forgetPendingStep($thread);
            $this->update($thread, LiveActivityState::Working, $label, 10);
        } elseif (LiveActivitySteps::needsPerson($reason)) {
            $this->end($thread, LiveActivityState::NeedsYou);
        }
    }

    /**
     * Drops the remembered label and any held-back update, so a queued step job sends nothing over a newer state and the next tool label always goes out.
     */
    private function forgetPendingStep(Thread $thread): void
    {
        try {
            Cache::forget(self::stepKey($thread));
            Cache::forget(self::pendingKey($thread));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function questionOpened(Thread $thread): void
    {
        $this->end($thread, LiveActivityState::NeedsYou);

        try {
            $runId = $thread->runs()->latest('id')->value('id');
            Cache::put(self::questionEndKey($thread, $runId), true, 3600);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function pullQuestionEnd(Thread $thread, TaskRun $run): bool
    {
        try {
            return Cache::pull(self::questionEndKey($thread, $run->id)) === true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private static function questionEndKey(Thread $thread, int|string|null $runId): string
    {
        return 'live-activity:question-end:' . $thread->id . ':' . $runId;
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
