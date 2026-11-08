<?php

namespace App\Services\Threads;

use App\Enums\ThreadStatus;
use App\Models\Thread;

/**
 * Closes a thread for good: its sandbox is destroyed and its channel is
 * forgotten. A closed thread never reopens.
 */
class ThreadCloser
{
    public function __construct(
        private readonly ThreadSandbox $sandbox,
        private readonly ThreadStreams $streams,
    ) {}

    public function close(Thread $thread, string $reason): void
    {
        if (! $thread->isOpen()) {
            return;
        }

        $this->sandbox->destroy($thread);

        $thread->forceFill([
            'status' => ThreadStatus::Closed,
            'closed_at' => now(),
            'close_reason' => $reason,
        ])->save();

        $this->streams->unregister($thread->channel());
    }
}
