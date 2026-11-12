<?php

namespace App\Jobs\Threads;

use App\Models\Thread;
use App\Services\Threads\PullRequests\SiblingBlockPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rewrites the sibling block of every open pull request of a thread.
 */
class RefreshSiblingBlocksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $threadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->threadId;
    }

    public function handle(SiblingBlockPublisher $publisher): void
    {
        $thread = Thread::query()->withoutGlobalScopes()->find($this->threadId);

        if ($thread !== null) {
            $publisher->publish($thread);
        }
    }
}
