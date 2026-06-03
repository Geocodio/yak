<?php

namespace App\Jobs\Middleware;

use App\Models\YakTask;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Serializes jobs that push to the same branch so two follow-up runs (or a
 * follow-up colliding with an in-flight run) never push concurrently. A
 * second job for the same branch is released back to the queue and retried.
 */
class PreventBranchOverlap extends WithoutOverlapping
{
    public function __construct(YakTask $task)
    {
        $key = $task->repo . ':' . ($task->branch_name ?? 'task-' . $task->id);

        parent::__construct($key);

        $this->releaseAfter(30)->expireAfter(3600);
    }
}
