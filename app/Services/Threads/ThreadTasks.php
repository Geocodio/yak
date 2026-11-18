<?php

namespace App\Services\Threads;

use App\Models\AiUsage;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Which task of a thread plays which part: the one that carries the request,
 * the one that works now, the one held for a co-owner and the one that owns
 * the pull request. A moved or continued thread may have none of them.
 */
final class ThreadTasks
{
    /**
     * The task the conversation started with. A thread made from an old task
     * reads it through its stored root, because its tasks may have moved on.
     */
    public static function request(Thread $thread): ?YakTask
    {
        if ($thread->legacy_root_task_id !== null) {
            return YakTask::query()->withoutGlobalScopes()->find($thread->legacy_root_task_id);
        }

        return $thread->tasks()->oldest('id')->first()?->rootTask();
    }

    /**
     * The task the thread works on now: its latest by id.
     */
    public static function head(Thread $thread): ?YakTask
    {
        return $thread->headTask();
    }

    public static function coOwnerRequest(Thread $thread): ?CoOwnerRequest
    {
        return CoOwnerRequest::query()
            ->whereIn('task_id', $thread->tasks()->select('tasks.id'))
            ->latest('id')
            ->first();
    }

    /**
     * The task of the thread with the latest co-owner request.
     */
    public static function coOwnerTask(Thread $thread): ?YakTask
    {
        return self::coOwnerRequest($thread)?->task;
    }

    public static function pullRequestTask(Thread $thread): ?YakTask
    {
        return $thread->pullRequestTask();
    }

    /**
     * Spend billed to the API for the given tasks.
     *
     * @param  iterable<int>|Builder  $taskIds
     */
    public static function apiSpendUsd(iterable|Builder $taskIds): float
    {
        return (float) AiUsage::query()->whereIn('yak_task_id', $taskIds)->sum('cost_usd');
    }
}
