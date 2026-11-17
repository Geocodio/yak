<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Api\App\Tasks\CoOwnerDecisionController;
use App\Http\Controllers\Api\App\Tasks\EarlyWarningController;
use App\Http\Controllers\Api\App\Threads\Concerns\AnswersThreadActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\RequestAppCoOwnerChangesRequest;
use App\Http\Requests\Api\App\TagAppCoOwnerRequest;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\Threads\ThreadTasks;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The co-owner decisions and the early warning answer, driven from the thread. The app task controllers run them, so who may take each decision is decided in the co-owner services for the task route and the thread route alike.
 */
class ThreadCoOwnerController extends Controller
{
    use AnswersThreadActions;

    public function approve(Request $request, Thread $thread, CoOwnerDecisionController $decisions): JsonResponse
    {
        return $this->decide($request, $thread, fn (YakTask $task): JsonResponse => $decisions->approve($request, $task));
    }

    public function requestChanges(RequestAppCoOwnerChangesRequest $request, Thread $thread, CoOwnerDecisionController $decisions): JsonResponse
    {
        return $this->decide($request, $thread, fn (YakTask $task): JsonResponse => $decisions->requestChanges($request, $task));
    }

    public function reject(Request $request, Thread $thread, CoOwnerDecisionController $decisions): JsonResponse
    {
        return $this->decide($request, $thread, fn (YakTask $task): JsonResponse => $decisions->reject($request, $task));
    }

    public function withdraw(Request $request, Thread $thread, CoOwnerDecisionController $decisions): JsonResponse
    {
        return $this->decide($request, $thread, fn (YakTask $task): JsonResponse => $decisions->withdraw($request, $task));
    }

    public function checkAccess(Request $request, Thread $thread, GitHubWriteAccess $writeAccess): JsonResponse
    {
        $coOwnerRequest = ThreadTasks::coOwnerRequest($thread) ?? $this->nothingTo('decide');

        $this->refuseClosed($thread);
        $writeAccess->forget($coOwnerRequest->repository, $request->user() ?? abort(401));

        return $this->answer($request, $thread, 'checked');
    }

    public function tag(TagAppCoOwnerRequest $request, Thread $thread, EarlyWarningController $warning): JsonResponse
    {
        return $this->decide($request, $thread, fn (YakTask $task): JsonResponse => $warning->tag($request, $task), isEarlyWarning: true);
    }

    public function decline(Request $request, Thread $thread, EarlyWarningController $warning): JsonResponse
    {
        return $this->decide($request, $thread, fn (YakTask $task): JsonResponse => $warning->decline($request, $task), isEarlyWarning: true);
    }

    public function candidates(Request $request, Thread $thread, EarlyWarningController $warning): JsonResponse
    {
        return $warning->candidates($request, $this->taskFor($thread, isEarlyWarning: true));
    }

    /**
     * @param  Closure(YakTask): JsonResponse  $decision
     */
    private function decide(Request $request, Thread $thread, Closure $decision, bool $isEarlyWarning = false): JsonResponse
    {
        $task = $this->taskFor($thread, $isEarlyWarning);

        $this->refuseClosed($thread);

        return $this->answer($request, $thread, (string) $decision($task)->getData(true)['status']);
    }

    /**
     * The task holding the latest co-owner request. The early warning comes before any request, so its answers fall back to the head task.
     */
    private function taskFor(Thread $thread, bool $isEarlyWarning): YakTask
    {
        $task = ThreadTasks::coOwnerTask($thread) ?? ($isEarlyWarning ? ThreadTasks::head($thread) : null);

        return $task ?? $this->nothingTo('decide');
    }
}
