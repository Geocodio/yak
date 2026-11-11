<?php

namespace App\Services\Threads;

use App\Enums\EvictionReason;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Decides whether another sandbox may wake and which paused sandbox gives up
 * its slot. Limits are checked in the order person share, global cap, memory.
 */
class SandboxCapacity
{
    public function __construct(private SandboxManager $sandboxes) {}

    /** Distinct awake containers; a shared research sandbox counts once. */
    public function awakeCount(): int
    {
        return $this->awakeThreads()->distinct()->count('threads.sandbox_container');
    }

    public function awakeCountFor(int $ownerUserId): int
    {
        return $this->awakeThreads()
            ->whereRaw('coalesce(roots.owner_user_id, threads.owner_user_id) = ?', [$ownerUserId])
            ->distinct()
            ->count('threads.sandbox_container');
    }

    public function canWake(Thread $thread): bool
    {
        $reason = $this->needsEviction($thread);

        return $reason === null || $this->evictionCandidate($thread, $reason) !== null;
    }

    public function needsEviction(Thread $thread): ?EvictionReason
    {
        $ownerUserId = $thread->effectiveOwnerUserId();

        if ($ownerUserId !== null && $this->awakeCountFor($ownerUserId) >= (int) config('yak.threads.person_awake_cap')) {
            return EvictionReason::PersonShare;
        }

        if ($this->awakeCount() >= (int) config('yak.threads.awake_cap')) {
            return EvictionReason::GlobalCap;
        }

        return $this->ramBelowFloor() ? EvictionReason::MemoryFloor : null;
    }

    /**
     * The oldest paused, idle, not busy sandbox that may be stopped for the reason.
     */
    public function evictionCandidate(Thread $thread, EvictionReason $reason): ?Thread
    {
        $cutoff = now()->subMinutes((int) config('yak.threads.eviction_grace_minutes'));
        $ownerUserId = $thread->effectiveOwnerUserId();

        $candidates = $this->awakeThreads()
            ->where('threads.sandbox_state', ThreadSandboxState::Paused->value)
            ->where(fn (Builder $query) => $query->whereNull('threads.last_activity_at')->orWhere('threads.last_activity_at', '<=', $cutoff))
            ->when($reason === EvictionReason::PersonShare && $ownerUserId !== null, fn (Builder $query) => $query->whereRaw('coalesce(roots.owner_user_id, threads.owner_user_id) = ?', [$ownerUserId]))
            ->where('threads.id', '!=', $thread->id)
            ->orderBy('threads.last_activity_at')
            ->orderBy('threads.id')
            ->select('threads.*')
            ->get();

        return $candidates->first(fn (Thread $candidate): bool => ! $candidate->isSandboxBusy() && ! $candidate->sharesParentSandbox());
    }

    public function ramBelowFloor(): bool
    {
        return $this->sandboxes->availableMemoryBytes() < (int) config('yak.threads.memory_floor_gb') * 1024 ** 3;
    }

    public function poolOverWatermark(): bool
    {
        return $this->sandboxes->poolUsedPercent() > (int) config('yak.threads.pool_watermark_percent');
    }

    /**
     * @return Collection<int, Thread>
     */
    public function destroyCandidates(): Collection
    {
        return Thread::query()->withoutGlobalScopes()
            ->where('sandbox_state', ThreadSandboxState::Stopped->value)
            ->whereIn('status', [ThreadStatus::Open->value, ThreadStatus::Closed->value])
            ->orderBy('last_activity_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<Thread>
     */
    private function awakeThreads(): Builder
    {
        return Thread::query()->withoutGlobalScopes()
            ->from('threads')
            ->leftJoin('threads as roots', 'roots.id', '=', 'threads.root_thread_id')
            ->whereIn('threads.sandbox_state', [ThreadSandboxState::Starting->value, ThreadSandboxState::Running->value, ThreadSandboxState::Paused->value])
            ->whereNotNull('threads.sandbox_container');
    }
}
