<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tasks\CoOwnerDecisionController;
use App\Http\Controllers\Tasks\EarlyWarningController;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Http\Requests\Tasks\RequestCoOwnerChangesRequest;
use App\Http\Requests\Tasks\TagCoOwnerRequest;
use App\Http\Resources\EarlyWarningData;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\Threads\ThreadTasks;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The co-owner decisions and the early warning answer, driven from the thread.
 * The task controllers run them, so who may take each decision is decided in
 * the co-owner services for the task route and the thread route alike.
 */
class ThreadCoOwnerController extends Controller
{
    use RespondsToThreadActions;

    public function approve(Request $request, Thread $thread, CoOwnerDecisionController $decisions): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $decisions->approve($request, $task));
    }

    public function requestChanges(RequestCoOwnerChangesRequest $request, Thread $thread, CoOwnerDecisionController $decisions): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $decisions->requestChanges($request, $task));
    }

    public function reject(Request $request, Thread $thread, CoOwnerDecisionController $decisions): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $decisions->reject($request, $task));
    }

    public function withdraw(Request $request, Thread $thread, CoOwnerDecisionController $decisions): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $decisions->withdraw($request, $task));
    }

    public function checkAccess(Request $request, Thread $thread, CoOwnerDecisionController $decisions, GitHubWriteAccess $writeAccess): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $decisions->checkAccess($request, $task, $writeAccess));
    }

    public function tag(TagCoOwnerRequest $request, Thread $thread, EarlyWarningController $warning): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $warning->tag($request, $task), isEarlyWarning: true);
    }

    public function decline(Request $request, Thread $thread, EarlyWarningController $warning): RedirectResponse
    {
        return $this->decide($thread, fn (YakTask $task): RedirectResponse => $warning->decline($request, $task), isEarlyWarning: true);
    }

    public function candidates(Request $request, Thread $thread): JsonResponse
    {
        $task = $this->taskFor($thread, isEarlyWarning: true);

        return response()->json(EarlyWarningData::candidatesWithin($task->rootTask(), $request->user()));
    }

    /**
     * @param  Closure(YakTask): RedirectResponse  $decision
     */
    private function decide(Thread $thread, Closure $decision, bool $isEarlyWarning = false): RedirectResponse
    {
        $task = $this->taskFor($thread, $isEarlyWarning);

        return $this->respond($thread, function () use ($thread, $task, $decision): RedirectResponse {
            $this->refuseClosed($thread);

            // The task controllers flash their outcome and redirect back; the flash is kept, the redirect goes to the thread.
            $decision($task);

            return redirect()->route('threads.show', $thread);
        });
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
