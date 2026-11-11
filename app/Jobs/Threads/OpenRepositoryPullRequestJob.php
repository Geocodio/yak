<?php

namespace App\Jobs\Threads;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Opens the pull request for a changes repo of a thread once its CI is green.
 */
class OpenRepositoryPullRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $threadRepositoryId)
    {
        $this->onQueue('default');
    }

    public function handle(): void {}
}
