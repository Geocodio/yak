<?php

namespace App\Services\Stacks;

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Services\Threads\ThreadAgents;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Cache;

/**
 * The Stop stack control: interrupts every running turn in a stack, kills the
 * research children's runners, drops children that were waiting for a slot,
 * and blocks further spawns. Nothing is destroyed, so work stays recoverable.
 */
class StopStack
{
    public function __construct(
        private readonly ThreadTurns $turns,
        private readonly ThreadCommands $commands,
        private readonly ChildCardPublisher $cards,
    ) {}

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

            // A stopped stack has no agents left to wake a thread again when they finish.
            if (ThreadAgent::hasRunning($thread->id)) {
                app(ThreadAgents::class)->stopAll($thread);
                $touched = true;
            }

            if ($thread->queued_for_slot_at !== null) {
                $thread->forceFill(['queued_for_slot_at' => null])->save();
                $touched = true;
            }

            if ($thread->isResearchChild() && $thread->isOpen() && $this->killOnce($thread, $root)) {
                $touched = true;
            }

            if ($touched && $thread->isChild()) {
                $this->cards->publish($thread);
            }

            $stopped += $touched ? 1 : 0;
        }

        return $stopped;
    }

    /**
     * Sends the kill once per stop stamp, so a repeated run sends nothing again.
     */
    private function killOnce(Thread $thread, Thread $root): bool
    {
        $key = "stack-stop-kill:{$thread->id}:{$root->stack_stopped_at?->timestamp}";

        if (Cache::has($key)) {
            return false;
        }

        $this->commands->kill($thread);
        Cache::put($key, true, now()->addDay());

        return true;
    }

    /**
     * Whether any thread of the stack still has a turn waiting or running.
     */
    public function hasActiveTurn(Thread $root): bool
    {
        return TaskRun::query()
            ->whereIn('thread_id', Thread::query()->withoutGlobalScopes()
                ->where(fn ($query) => $query->whereKey($root->id)->orWhere('root_thread_id', $root->id))
                ->select('id'))
            ->whereIn('turn_state', [...ThreadTurns::WAITING_STATES, TurnState::Running])
            ->exists();
    }
}
