<?php

namespace App\Services\Threads;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Stacks\StackBranchCleanup;
use App\Services\Stacks\StackThreadCloser;
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
        private readonly StackThreadCloser $stackCloser,
        private readonly StackBranchCleanup $branchCleanup,
    ) {}

    public function close(Thread $thread, string $reason): void
    {
        if (! $thread->isOpen()) {
            return;
        }

        if ($reason === 'idle' && $thread->owner_side === ThreadOwnerSide::Device) {
            return;
        }

        if ($thread->isRoot()) {
            $this->stackCloser->closeChildren($thread);
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

        // An idle close keeps child branches: nothing was merged, so they may hold the only copy of the work.
        if ($thread->isRoot() && $reason !== 'idle') {
            $this->branchCleanup->deleteChildBranches($thread);
        }
    }
}
