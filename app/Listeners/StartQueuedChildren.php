<?php

namespace App\Listeners;

use App\Events\ThreadSandboxSlotFreed;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\Thread;
use App\Services\Stacks\ChildAwakeLimit;

/**
 * Starts waiting children when a sandbox slot or a child's turn frees room
 * under a parent's awake limit.
 */
class StartQueuedChildren
{
    public function __construct(private readonly ChildAwakeLimit $limit) {}

    public function handleSlotFreed(ThreadSandboxSlotFreed $event): void
    {
        Thread::query()->withoutGlobalScopes()
            ->whereIn('id', Thread::query()->withoutGlobalScopes()->whereNotNull('queued_for_slot_at')->select('parent_thread_id'))
            ->get()
            ->each(fn (Thread $parent) => $this->limit->startNextQueued($parent));
    }

    public function handleCompleted(TurnCompleted $event): void
    {
        $this->startFor($event->run->thread_id);
    }

    public function handleFailed(TurnFailed $event): void
    {
        $this->startFor($event->run->thread_id);
    }

    private function startFor(?int $threadId): void
    {
        $child = $threadId === null ? null : Thread::query()->withoutGlobalScopes()->find($threadId);
        $parent = $child?->isChild() ? Thread::query()->withoutGlobalScopes()->find($child->parent_thread_id) : null;

        if ($parent !== null) {
            $this->limit->startNextQueued($parent);
        }
    }
}
