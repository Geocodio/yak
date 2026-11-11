<?php

namespace App\Services\Stacks;

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\ThreadBusy;

/**
 * How many implement, mockup and general children of one parent may hold a
 * sandbox at once. Research children share the parent's container and never
 * count. A child over the limit waits with `queued_for_slot_at` set.
 */
class ChildAwakeLimit
{
    private const AWAKE_STATES = [ThreadSandboxState::Starting, ThreadSandboxState::Running, ThreadSandboxState::Paused];

    public function hasFreeSlot(Thread $parent): bool
    {
        $awake = Thread::query()->withoutGlobalScopes()
            ->where('parent_thread_id', $parent->id)
            ->where('kind', '!=', ThreadKind::Research->value)
            ->where('status', ThreadStatus::Open->value)
            ->whereIn('sandbox_state', array_map(fn (ThreadSandboxState $state): string => $state->value, self::AWAKE_STATES))
            ->count();

        return $awake < $parent->child_awake_limit;
    }

    public function set(Thread $parent, int $limit): void
    {
        $parent->forceFill(['child_awake_limit' => max(1, min($limit, (int) config('yak.threads.person_awake_cap')))])->save();
        $this->startNextQueued($parent);
    }

    /**
     * Starts the oldest queued child while a slot is free.
     */
    public function startNextQueued(Thread $parent): void
    {
        while ($this->hasFreeSlot($parent)) {
            $next = Thread::query()->withoutGlobalScopes()
                ->where('parent_thread_id', $parent->id)
                ->whereNotNull('queued_for_slot_at')
                ->where('status', ThreadStatus::Open->value)
                ->orderBy('queued_for_slot_at')
                ->orderBy('id')
                ->first();

            if ($next === null || $next->rootThread()->stack_stopped_at !== null) {
                return;
            }

            try {
                app(ChildSpawner::class)->start($next);
            } catch (ThreadBusy) {
                return;
            }

            if ($next->fresh()?->queued_for_slot_at !== null) {
                return;
            }
        }
    }
}
