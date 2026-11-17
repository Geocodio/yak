<?php

namespace App\Http\Controllers\Threads;

use App\Actions\RequestReReview;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Http\Requests\Api\App\StoreThreadClarificationAnswersRequest;
use App\Http\Requests\Tasks\RerouteTaskRequest;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClarificationAnswerSubmitter;
use App\Services\Tasks\TaskActions;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTasks;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The task actions of the old task page, driven from the thread. Each route
 * finds the task of the thread the action belongs to and calls the same code
 * as the task routes; the thread routes add the thread's own guards.
 */
class ThreadTaskActionController extends Controller
{
    use RespondsToThreadActions;

    public function retry(Thread $thread, TaskActions $actions): RedirectResponse
    {
        $isClosedLegacy = $thread->is_legacy && ! $thread->isOpen();
        $head = $isClosedLegacy ? null : ThreadTasks::head($thread);

        if ($head === null) {
            $this->nothingTo('retry');
        }

        return $this->respond($thread, function () use ($thread, $head, $actions): string {
            $this->refuseClosed($thread);
            $this->requireRepository($thread);

            return $actions->retry($head)->sentence;
        });
    }

    public function setUpRepositoryAndContinue(Request $request, Thread $thread, TaskActions $actions): RedirectResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('retry');

        return $this->respond($thread, function () use ($request, $thread, $head, $actions): string {
            $this->refuseClosed($thread);
            $this->requireRepository($thread);

            return $actions->setUpRepositoryAndContinue($head, $this->user($request))->sentence;
        });
    }

    public function cancel(Request $request, Thread $thread, TaskActions $actions, ThreadTurns $turns): RedirectResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('cancel');

        return $this->respond($thread, function () use ($request, $thread, $head, $actions, $turns): string {
            $this->refuseClosed($thread);
            $turns->stop($thread);

            return $actions->cancel($head, $this->user($request))->sentence;
        });
    }

    public function move(RerouteTaskRequest $request, Thread $thread, TaskActions $actions): RedirectResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('move');

        return $this->respond($thread, function () use ($request, $thread, $head, $actions): RedirectResponse {
            $this->refuseClosed($thread);
            $sentence = $actions->reroute($head, $request->validated('repo'))->sentence;
            $current = $head->refresh()->thread;
            $redirect = redirect()->route('threads.show', $current);

            return $sentence === '' ? $redirect : $redirect->with('success', $sentence);
        });
    }

    public function requestReview(Request $request, Thread $thread, RequestReReview $requestReReview, ThreadAccess $access): RedirectResponse
    {
        $task = ThreadTasks::pullRequestTask($thread);

        if ($task === null || blank($task->pr_url)) {
            $this->nothingTo('review');
        }

        return $this->respond($thread, function () use ($request, $thread, $task, $requestReReview, $access): RedirectResponse {
            $this->refuseClosed($thread);
            $this->requireRepository($thread);

            $outcome = $requestReReview($task);
            $reviewThread = $outcome->isNewReview ? $outcome->task->thread : null;
            $target = $reviewThread !== null && $access->canSee($request->user(), $reviewThread) ? $reviewThread : $thread;

            return redirect()->route('threads.show', $target)->with($outcome->isError ? 'error' : 'success', $outcome->sentence);
        });
    }

    public function retryRender(Thread $thread, TaskActions $actions): RedirectResponse
    {
        $task = ThreadTasks::pullRequestTask($thread) ?? $this->nothingTo('render');

        return $this->respond($thread, function () use ($thread, $task, $actions): string {
            $this->refuseClosed($thread);
            $this->requireRepository($thread);

            return $actions->retryRender($task)->sentence;
        });
    }

    public function answerClarification(StoreThreadClarificationAnswersRequest $request, Thread $thread, ClarificationAnswerSubmitter $submitter): RedirectResponse
    {
        return $this->respond($thread, function () use ($request, $submitter): string {
            $submitted = $submitter->submit(
                $request->head(),
                $request->answers(),
                $request->validated('note'),
                (string) ($request->user()->name ?? 'Someone'),
                'dashboard',
            );

            if (! $submitted) {
                throw ThreadActionRefused::because(ThreadActionRefused::QUESTION_CLOSED, StoreThreadClarificationAnswersRequest::CLOSED_SENTENCE);
            }

            return 'Sent to Yak.';
        });
    }

    private function user(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
