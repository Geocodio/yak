<?php

namespace App\Services\Stacks;

use App\Models\Thread;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadTurns;

/**
 * The Stop stack control: interrupts every running turn in a stack, kills the
 * research children's runners, drops children that were waiting for a slot,
 * and blocks further spawns. Nothing is destroyed, so work stays recoverable.
 */
class StopStack
{
    public function __construct(private readonly ThreadTurns $turns, private readonly ThreadCommands $commands) {}

    /**
     * @return int how many threads were stopped
     */
    public function stop(Thread $root): int
    {
        $root = $root->rootThread();
        $root->forceFill(['stack_stopped_at' => $root->stack_stopped_at ?? now()])->save();

        $stopped = 0;
        $threads = Thread::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->whereKey($root->id)->orWhere('root_thread_id', $root->id))
            ->orderBy('id')
            ->get();

        foreach ($threads as $thread) {
            $touched = false;

            if (ThreadTurns::activeTurn($thread) !== null) {
                $this->turns->stop($thread);
                $touched = true;
            }

            if ($thread->queued_for_slot_at !== null) {
                $thread->forceFill(['queued_for_slot_at' => null])->save();
                $touched = true;
            }

            if ($thread->isResearchChild() && $thread->isOpen()) {
                $this->commands->kill($thread);
                $touched = true;
            }

            $stopped += $touched ? 1 : 0;
        }

        return $stopped;
    }
}
