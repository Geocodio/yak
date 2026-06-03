<?php

namespace App\Jobs\Middleware;

use App\Models\YakTask;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Serializes jobs that push to the same branch so two follow-up runs (or a
 * follow-up colliding with an in-flight run) never push concurrently. A
 * second job for the same branch is released back to the queue and retried.
 *
 * Note: expireAfter (4200s) intentionally outlasts RunFollowUpJob's 3600s
 * timeout so the lock cannot expire mid-run and allow a second job to push
 * to the same branch concurrently.
 */
class PreventBranchOverlap extends WithoutOverlapping
{
    public function __construct(YakTask $task)
    {
        $key = $task->repo . ':' . ($task->branch_name ?? 'task-' . $task->id);

        parent::__construct($key);

        // expireAfter must outlast RunFollowUpJob's 3600s timeout so the lock
        // can't expire mid-run and let a second job push to the same branch.
        $this->releaseAfter(30)->expireAfter(4200);
    }
}
