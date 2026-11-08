<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Uploads a finished turn's session files and freezes the thread's sandbox.
 * It runs on a worker so the thread driver keeps handling every thread's
 * output while the sync and the pause take their time. A sandbox that a turn
 * or an after-turn job is using stays awake; that job pauses it when it ends.
 */
class PauseThreadSandboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $threadId)
    {
        $this->onQueue('yak-claude');
    }

    public function handle(ThreadSandbox $sandbox): void
    {
        $thread = Thread::query()->find($this->threadId);

        if ($thread !== null) {
            $sandbox->pause($thread, syncSessions: true);
        }
    }
}
