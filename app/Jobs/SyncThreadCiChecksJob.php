<?php

namespace App\Jobs;

use App\Models\Thread;
use App\Services\Threads\ThreadCiChecks;
use App\Services\Threads\ThreadShowChanged;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a thread's GitHub checks again after GitHub reports a workflow job, and tells open thread
 * pages to reload when what the CI card shows changed. One pending job per thread absorbs the burst
 * of job events a push starts; an event that arrives once it runs queues the next read.
 */
class SyncThreadCiChecksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const DELAY_SECONDS = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $threadId)
    {
        $this->onQueue('default');
        $this->delay(self::DELAY_SECONDS);
    }

    public function uniqueId(): string
    {
        return (string) $this->threadId;
    }

    public function handle(): void
    {
        $thread = Thread::withoutGlobalScopes()->find($this->threadId);

        if ($thread === null) {
            return;
        }

        ThreadCiChecks::forgetBranch($thread);

        if (ThreadCiChecks::rememberFingerprint($thread)) {
            ThreadShowChanged::publish($thread->id);
        }
    }
}
