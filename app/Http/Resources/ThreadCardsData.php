<?php

namespace App\Http\Resources;

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use App\Services\Tasks\TaskStopSummary;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTasks;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Collection;

/**
 * The cards above the thread composer: the one thing that needs a person
 * before the thread goes on, and why a thread needs a person at all. The
 * array shapes below are the source of truth for the TypeScript types.
 *
 * @phpstan-import-type Notice from EarlyWarningData
 * @phpstan-import-type Panel from CoOwnerPanelData
 *
 * @phpstan-type ThreadCards array{
 *     repositoryQuestion: array{prompt: string, options: list<string>, selected: ?string, canAnswer: bool}|null,
 *     earlyWarning: Notice|null,
 *     coOwner: Panel|null,
 *     stopped: array{kind: string, summary: string, detail: string|null, nextStep: string, isWaitingForSetup: bool}|null,
 *     actions: array{canStop: bool, canRetry: bool, canCancel: bool, canMove: bool, moveTargets: list<string>, canRequestReview: bool, canRetryRender: bool},
 *     card: 'question'|'repository_question'|'early_warning'|'co_owner'|'stopped'|null,
 * }
 */
final class ThreadCardsData
{
    private const array NO_ACTIONS = ['canStop' => false, 'canRetry' => false, 'canCancel' => false, 'canMove' => false, 'moveTargets' => [], 'canRequestReview' => false, 'canRetryRender' => false];

    /**
     * @return ThreadCards
     */
    public static function for(Thread $thread, User $viewer): array
    {
        $head = ThreadTasks::head($thread);

        if ($head === null || ! $thread->isOpen() || $thread->is_legacy) {
            return ['repositoryQuestion' => null, 'earlyWarning' => null, 'coOwner' => null, 'stopped' => null, 'actions' => self::NO_ACTIONS, 'card' => null];
        }

        $canDrive = app(ThreadAccess::class)->canDrive($viewer, $thread);
        $root = ThreadTasks::request($thread) ?? $head;
        $coOwnerRequest = ThreadTasks::coOwnerRequest($thread);
        $coOwner = CoOwnerPanelData::forRequest($coOwnerRequest, $viewer);

        if ($coOwner !== null) {
            $coOwner['connectUrl'] = GitHubAccountController::connectUrl(route('threads.show', $thread, false));
        }

        $earlyWarning = EarlyWarningData::forTask($root, $coOwnerRequest, $viewer);
        $repositoryQuestion = self::repositoryQuestion($thread, $head, $canDrive);
        $stopped = self::stopped($head);
        $hasQuestion = $head->status === TaskStatus::AwaitingClarification && $head->pendingClarificationQuestions() !== [];

        $card = match (true) {
            $hasQuestion => 'question',
            $repositoryQuestion !== null => 'repository_question',
            $earlyWarning !== null && $earlyWarning['state'] === 'shown' && $earlyWarning['canPick'] && $canDrive => 'early_warning',
            $coOwner !== null && $coOwner['status'] === CoOwnerRequestStatus::Pending->value => 'co_owner',
            $stopped !== null => 'stopped',
            default => null,
        };

        return [
            'repositoryQuestion' => $repositoryQuestion,
            'earlyWarning' => $earlyWarning,
            'coOwner' => $coOwner,
            'stopped' => $stopped,
            'actions' => $canDrive ? self::actions($thread, $head, $coOwnerRequest) : self::NO_ACTIONS,
            'card' => $card,
        ];
    }

    /**
     * @return array{prompt: string, options: list<string>, selected: ?string, canAnswer: bool}|null
     */
    private static function repositoryQuestion(Thread $thread, YakTask $head, bool $canDrive): ?array
    {
        if ($thread->hasRepository()) {
            return null;
        }

        /** @var list<string> $options */
        $options = array_values((array) ($head->clarification_options ?? []));

        if ($options === [] && ! RepoClarificationResolver::awaitingRepoChoice($head)) {
            return null;
        }

        if ($options === []) {
            $options = Repository::where('is_active', true)->orderBy('slug')->pluck('slug')->all();
        }

        $requested = request()->query('repo');

        return [
            'prompt' => 'Which repository is this for?',
            'options' => $options,
            'selected' => is_string($requested) && in_array($requested, $options, true) ? $requested : null,
            'canAnswer' => $canDrive,
        ];
    }

    /**
     * @return array{kind: string, summary: string, detail: string|null, nextStep: string, isWaitingForSetup: bool}|null
     */
    private static function stopped(YakTask $head): ?array
    {
        if ($head->status === TaskStatus::Cancelled) {
            return ['kind' => 'cancelled', 'summary' => 'This request was cancelled.', 'detail' => null, 'nextStep' => 'Start a new request to try again.', 'isWaitingForSetup' => false];
        }

        return TaskStopSummary::for($head);
    }

    /**
     * The same flags the task page computes for the head task, plus the thread's own: stopping a turn,
     * and the pull request actions, which belong to the task that owns the pull request.
     *
     * @return array{canStop: bool, canRetry: bool, canCancel: bool, canMove: bool, moveTargets: list<string>, canRequestReview: bool, canRetryRender: bool}
     */
    private static function actions(Thread $thread, YakTask $head, ?CoOwnerRequest $coOwnerRequest): array
    {
        $hasRepository = $thread->hasRepository();
        $headActions = TaskDetailData::actions($head, $head, $coOwnerRequest, $head->parent_task_id !== null);
        $pullRequestTask = ThreadTasks::pullRequestTask($thread);
        $pullRequestActions = $pullRequestTask === null ? null : ($pullRequestTask->is($head) ? $headActions : TaskDetailData::actions($pullRequestTask, $pullRequestTask, $coOwnerRequest, $pullRequestTask->parent_task_id !== null));

        return [
            'canStop' => ThreadTurns::activeTurn($thread) !== null,
            'canRetry' => $hasRepository && $headActions['canRetry'],
            'canCancel' => $headActions['canCancel'],
            'canMove' => $hasRepository && $headActions['canReroute'],
            'moveTargets' => $hasRepository ? array_values($headActions['rerouteTargets']) : [],
            'canRequestReview' => $hasRepository && ($pullRequestActions['canRequestReview'] ?? false),
            'canRetryRender' => $hasRepository && ($pullRequestActions['canRetryRender'] ?? false),
        ];
    }

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
