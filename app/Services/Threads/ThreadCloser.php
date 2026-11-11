<?php

namespace App\Services\Threads;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;

/**
 * Closes a thread for good: its sandbox is destroyed and its channel is
 * forgotten. A closed thread never reopens. A thread owned by a local
 * machine outlives idleness and closes only when its pull request ends.
 */
class ThreadCloser
{
    public function __construct(
        private readonly ThreadSandbox $sandbox,
        private readonly BrokerStreams $streams,
    ) {}

    public function close(Thread $thread, string $reason): void
    {
        if (! $thread->isOpen()) {
            return;
        }

        if ($reason === 'idle' && $thread->owner_side === ThreadOwnerSide::Device) {
            return;
        }

        $this->sandbox->destroy($thread);

        $thread->forceFill([
            'status' => ThreadStatus::Closed,
            'closed_at' => now(),
            'close_reason' => $reason,
        ])->save();

        $channel = "threads:{$thread->id}";
        $this->streams->unregister($channel);
        $this->streams->delete($channel);
    }
}
