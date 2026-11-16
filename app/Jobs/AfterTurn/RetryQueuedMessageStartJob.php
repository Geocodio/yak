<?php

namespace App\Jobs\AfterTurn;

use App\Models\TaskRun;
use App\Services\Threads\QueuedMessageRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts the turn for the messages queued during a completed turn again, after the first start met a lock that did not free up in time.
 */
class RetryQueuedMessageStartJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $taskRunId, public readonly int $attempt)
    {
        $this->onQueue('yak-claude');
    }

    public function handle(QueuedMessageRunner $runner): void
    {
        $run = TaskRun::query()->find($this->taskRunId);

        if ($run !== null) {
            $runner->afterTurn($run, $this->attempt);
        }
    }
}
