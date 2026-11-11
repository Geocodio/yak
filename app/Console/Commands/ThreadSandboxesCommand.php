<?php

namespace App\Console\Commands;

use App\Enums\EvictionReason;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Events\ThreadSandboxSlotFreed;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\IncusThreadSandboxManager;
use App\Services\Threads\RefreshTriggers;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('yak:thread-sandboxes')]
#[Description('Run the thread sandbox lifecycle: stop idle, destroy old, enforce capacity, release waiting threads')]
class ThreadSandboxesCommand extends Command
{
    public function handle(ThreadSandbox $sandboxes, SandboxCapacity $capacity, SandboxManager $manager): int
    {
        $this->reconcile($manager);
        $this->pauseWokenWithoutTurn($sandboxes);
        $this->stopIdlePaused($sandboxes);
        $this->destroyOldStopped($sandboxes);
        $this->enforcePoolWatermark($sandboxes, $capacity);
        $this->enforceMemoryFloor($sandboxes, $capacity);
        $this->releaseWaitingThreads($capacity);

        return self::SUCCESS;
    }

    /** Records sandboxes that stopped behind our back; only the real manager can tell. */
    private function reconcile(SandboxManager $manager): void
    {
        if (! $manager instanceof IncusThreadSandboxManager) {
            return;
        }

        $this->withState([ThreadSandboxState::Running, ThreadSandboxState::Paused])->get()
            ->unique('sandbox_container')
            ->each(function (Thread $thread) use ($manager): void {
                if ($manager->state($thread) === ThreadSandboxState::Stopped) {
                    Thread::withoutGlobalScopes()->where('sandbox_container', $thread->sandbox_container)
                        ->update(['sandbox_state' => ThreadSandboxState::Stopped->value, 'sandbox_state_changed_at' => now()]);
                }
            });
    }

    private function pauseWokenWithoutTurn(ThreadSandbox $sandboxes): void
    {
        $cutoff = now()->subMinutes((int) config('yak.threads.wake_pause_grace_minutes'));

        $this->withState([ThreadSandboxState::Running])
            ->where(fn (Builder $query) => $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<=', $cutoff))
            ->where('sandbox_state_changed_at', '<=', $cutoff)
            ->get()
            ->each(fn (Thread $thread) => $sandboxes->pauseIfIdle($thread));
    }

    private function stopIdlePaused(ThreadSandbox $sandboxes): void
    {
        $cutoff = now()->subMinutes((int) config('yak.threads.pause_to_stop_minutes'));

        $this->withState([ThreadSandboxState::Paused])
            ->where('last_activity_at', '<=', $cutoff)
            ->orderBy('last_activity_at')
            ->get()
            ->each(fn (Thread $thread) => $sandboxes->stop($thread, true));
    }

    private function destroyOldStopped(ThreadSandbox $sandboxes): void
    {
        $defaultHours = (int) config('yak.threads.stop_to_destroy_hours');

        $this->withState([ThreadSandboxState::Stopped])
            ->get()
            ->filter(function (Thread $thread) use ($defaultHours): bool {
                if ($thread->status === ThreadStatus::Closed) {
                    return true;
                }

                $idleSince = $thread->last_activity_at ?? $thread->sandbox_state_changed_at;

                return $idleSince !== null && $idleSince->lte(now()->subHours(RefreshTriggers::clampDestroyAfterHours($thread->destroy_after_hours) ?? $defaultHours));
            })
            ->each(fn (Thread $thread) => $sandboxes->destroy($thread));
    }

    private function enforcePoolWatermark(ThreadSandbox $sandboxes, SandboxCapacity $capacity): void
    {
        foreach ($capacity->destroyCandidates() as $candidate) {
            if (! $capacity->poolOverWatermark()) {
                return;
            }

            $sandboxes->destroy($candidate);
        }
    }

    private function enforceMemoryFloor(ThreadSandbox $sandboxes, SandboxCapacity $capacity): void
    {
        while ($capacity->ramBelowFloor()) {
            $candidate = $capacity->evictionCandidate(new Thread, EvictionReason::MemoryFloor);

            if ($candidate === null) {
                return;
            }

            $sandboxes->stop($candidate, true);

            if ($candidate->fresh()?->sandbox_state !== ThreadSandboxState::Stopped) {
                return;
            }
        }
    }

    private function releaseWaitingThreads(SandboxCapacity $capacity): void
    {
        $freeSlots = (int) config('yak.threads.awake_cap') - $capacity->awakeCount();

        if ($freeSlots <= 0) {
            return;
        }

        $waiting = Thread::query()->withoutGlobalScopes()
            ->where('blocked_reason', ThreadBlockReason::WaitingForSandbox->value)
            ->orderBy('blocked_at')
            ->orderBy('id')
            ->get();

        foreach ($waiting as $thread) {
            if ($freeSlots <= 0 || $capacity->ramBelowFloor()) {
                return;
            }

            if ($capacity->needsEviction($thread) !== null) {
                continue;
            }

            $thread->update(['blocked_reason' => null, 'blocked_at' => null]);
            ThreadSandboxSlotFreed::dispatch($thread);
            $freeSlots--;
        }
    }

    /**
     * @param  list<ThreadSandboxState>  $states
     * @return Builder<Thread>
     */
    private function withState(array $states): Builder
    {
        return Thread::query()->withoutGlobalScopes()
            ->whereIn('sandbox_state', array_map(fn (ThreadSandboxState $state): string => $state->value, $states))
            ->whereNotNull('sandbox_container')
            ->where(fn (Builder $query) => $query->whereNull('parent_thread_id')->orWhere('kind', '!=', ThreadKind::Research->value));
    }
}
