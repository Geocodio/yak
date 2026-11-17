<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Actions\RequestReReview;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Api\App\Threads\Concerns\AnswersThreadActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\RerouteAppTaskRequest;
use App\Models\Thread;
use App\Models\User;
use App\Services\Tasks\TaskActions;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTasks;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The task actions of the thread, for the app. Each route finds the task of the thread and runs the same code as the web thread routes and the app task routes.
 */
class ThreadTaskActionController extends Controller
{
    use AnswersThreadActions;

    public function retry(Request $request, Thread $thread, TaskActions $actions): JsonResponse
    {
        $isClosedLegacy = $thread->is_legacy && ! $thread->isOpen();
        $head = $isClosedLegacy ? null : ThreadTasks::head($thread);

        if ($head === null) {
            $this->nothingTo('retry');
        }

        $this->refuseClosed($thread);
        $this->requireRepository($thread);

        return $this->answer($request, $thread, $actions->retry($head, isTimeBounded: true)->status);
    }

    public function setUpRepositoryAndContinue(Request $request, Thread $thread, TaskActions $actions): JsonResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('retry');

        $this->refuseClosed($thread);
        $this->requireRepository($thread);

        return $this->answer($request, $thread, $actions->setUpRepositoryAndContinue($head, $this->user($request))->status);
    }

    public function cancel(Request $request, Thread $thread, TaskActions $actions, ThreadTurns $turns): JsonResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('cancel');

        $this->refuseClosed($thread);
        $turns->stop($thread);

        return $this->answer($request, $thread, $actions->cancel($head, $this->user($request))->status);
    }

    public function move(RerouteAppTaskRequest $request, Thread $thread, TaskActions $actions, ThreadAccess $access): JsonResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('move');

        $this->refuseClosed($thread);
        $status = $actions->reroute($head, $request->validated('repo'))->status;
        $current = $head->refresh()->thread;
        $shown = $current !== null && $access->canSee($this->user($request), $current) ? $current : $thread;

        return $this->answer($request, $shown, $status);
    }

    public function requestReview(Request $request, Thread $thread, RequestReReview $requestReReview): JsonResponse
    {
        $task = ThreadTasks::pullRequestTask($thread);

        if ($task === null || blank($task->pr_url)) {
            $this->nothingTo('review');
        }

        $this->refuseClosed($thread);
        $this->requireRepository($thread);

        $outcome = $requestReReview($task);

        if (! $outcome->isNewReview) {
            throw ThreadActionRefused::because(ThreadActionRefused::REVIEW_NOT_REQUESTED, $outcome->sentence);
        }

        return $this->answer($request, $thread, 'queued');
    }

    public function retryRender(Request $request, Thread $thread, TaskActions $actions): JsonResponse
    {
        $task = ThreadTasks::pullRequestTask($thread) ?? $this->nothingTo('render');

        $this->refuseClosed($thread);
        $this->requireRepository($thread);

        return $this->answer($request, $thread, $actions->retryRender($task)->status);
    }

    private function user(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
