<?php

namespace App\Http\Resources;

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Collection;

/**
 * The cards above the thread composer: the one thing that needs a person
 * before the thread goes on, and why a thread needs a person at all.
 */
final class ThreadCardsData
{
    /**
     * Why the thread needs this viewer, as one of `co_owner`, `early_warning`
     * and `repository_question`, or null. Two queries at most.
     *
     * @return 'co_owner'|'early_warning'|'repository_question'|null
     */
    public static function needsYouReason(Thread $thread, User $viewer): ?string
    {
        return self::needsYouReasons(collect([$thread]), $viewer)[$thread->id];
    }

    /**
     * The reason of every thread, keyed by thread id, in two queries however many threads there are.
     *
     * @param  Collection<int, Thread>  $threads
     * @return array<int, 'co_owner'|'early_warning'|'repository_question'|null>
     */
    public static function needsYouReasons(Collection $threads, User $viewer): array
    {
        $reasons = $threads->mapWithKeys(fn (Thread $thread): array => [$thread->id => null])->all();
        $openThreads = $threads->filter(fn (Thread $thread): bool => $thread->isOpen() && ! $thread->is_legacy);

        if ($openThreads->isEmpty()) {
            return $reasons;
        }

        $threadIds = $openThreads->pluck('id')->all();

        $requestsByThread = CoOwnerRequest::query()
            ->join('tasks', 'tasks.id', '=', 'co_owner_requests.task_id')
            ->whereIn('tasks.thread_id', $threadIds)
            ->get(['tasks.thread_id as thread_id', 'co_owner_requests.status', 'co_owner_requests.asked_user_ids'])
            ->groupBy('thread_id');

        $tasksByThread = YakTask::query()->withoutGlobalScopes()
            ->whereIn('thread_id', $threadIds)
            ->orderBy('id')
            ->get(['id', 'thread_id', 'parent_task_id', 'started_by_user_id', 'slack_user_id', 'early_warning', 'tagged_co_owner_user_id'])
            ->groupBy('thread_id');

        foreach ($openThreads as $thread) {
            $requests = $requestsByThread->get($thread->id, collect());
            $tasks = $tasksByThread->get($thread->id, collect());

            $reasons[$thread->id] = match (true) {
                self::isAskedForCoOwner($requests, $viewer) => 'co_owner',
                self::mustAnswerEarlyWarning($requests, $tasks, $viewer) => 'early_warning',
                self::mustAnswerRepositoryQuestion($thread, $tasks, $viewer) => 'repository_question',
                default => null,
            };
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, CoOwnerRequest>  $requests
     */
    private static function isAskedForCoOwner(Collection $requests, User $viewer): bool
    {
        return $requests->contains(fn (CoOwnerRequest $request): bool => $request->status === CoOwnerRequestStatus::Pending
            && in_array($viewer->id, $request->asked_user_ids, true));
    }

    /**
     * The requester owes the early warning an answer while it is shown and no co-owner request exists.
     *
     * @param  Collection<int, CoOwnerRequest>  $requests
     * @param  Collection<int, YakTask>  $tasks
     */
    private static function mustAnswerEarlyWarning(Collection $requests, Collection $tasks, User $viewer): bool
    {
        if ($requests->isNotEmpty()) {
            return false;
        }

        $root = $tasks->first(fn (YakTask $task): bool => $task->early_warning !== null && $task->parent_task_id === null);

        if ($root === null) {
            return false;
        }

        $state = $root->early_warning['state'] ?? null;
        $isShown = $state === 'shown' || ($state === 'tagged' && $root->tagged_co_owner_user_id === null);

        return $isShown && self::isRequester($root, $viewer);
    }

    /**
     * @param  Collection<int, YakTask>  $tasks
     */
    private static function mustAnswerRepositoryQuestion(Thread $thread, Collection $tasks, User $viewer): bool
    {
        if ($thread->hasRepository()) {
            return false;
        }

        $first = $tasks->first();
        $requesterUserId = $thread->created_by_user_id ?? $first?->started_by_user_id;

        if ($requesterUserId !== null) {
            return $requesterUserId === $viewer->id;
        }

        if (filled($first?->slack_user_id)) {
            return $first->slack_user_id === $viewer->slack_user_id;
        }

        return app(ThreadAccess::class)->canDrive($viewer, $thread);
    }

    private static function isRequester(YakTask $root, User $viewer): bool
    {
        if ($root->started_by_user_id !== null) {
            return $root->started_by_user_id === $viewer->id;
        }

        return filled($root->slack_user_id) && $root->slack_user_id === $viewer->slack_user_id;
    }
}
