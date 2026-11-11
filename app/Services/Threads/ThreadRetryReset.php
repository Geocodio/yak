<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Models\Thread;
use App\Models\YakTask;

/**
 * Readies a thread for a dashboard Retry that starts over: the next turn runs
 * in a new Claude session on a clean tree.
 */
class ThreadRetryReset
{
    public function __construct(private readonly ThreadSandbox $sandbox) {}

    public function prepare(YakTask $task): void
    {
        if ($task->thread_id === null) {
            return;
        }

        $thread = Thread::query()->find($task->thread_id);

        if ($thread === null || ! $thread->runs()->exists()) {
            return;
        }

        if ($thread->runner_state !== RunnerState::None) {
            $this->sandbox->stop($thread);
            $thread->refresh();
        }

        $thread->forceFill(['fresh_session_pending' => true])->save();
    }
}
