<?php

namespace App\Services\Threads;

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;

/**
 * Entry point for starting, steering and stopping a thread's turns.
 */
class ThreadTurns
{
    /**
     * The thread's latest turn that is running on the runner.
     */
    public static function runningTurn(Thread $thread): ?TaskRun
    {
        return TaskRun::query()
            ->where('thread_id', $thread->id)
            ->where('turn_state', TurnState::Running)
            ->latest('id')
            ->first();
    }

    /**
     * The thread's latest turn that has not ended, including turns still
     * waiting for an account or a sandbox.
     */
    public static function activeTurn(Thread $thread): ?TaskRun
    {
        return TaskRun::query()
            ->where('thread_id', $thread->id)
            ->whereIn('turn_state', [
                TurnState::Pending,
                TurnState::WaitingForAccount,
                TurnState::WaitingForSandbox,
                TurnState::Running,
            ])
            ->latest('id')
            ->first();
    }
}
