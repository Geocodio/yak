<?php

namespace App\Http\Controllers\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Http\Requests\Api\App\StoreThreadClarificationAnswersRequest;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClarificationAnswerSubmitter;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTaskActions;
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

    public function __construct(private readonly ThreadTaskActions $actions) {}

    public function retry(Thread $thread): RedirectResponse
    {
        return $this->respond($thread, fn (): string => $this->actions->retry($thread)->sentence);
    }

    public function setUpRepositoryAndContinue(Request $request, Thread $thread): RedirectResponse
    {
        return $this->respond($thread, fn (): string => $this->actions->setUpRepositoryAndContinue($thread, $this->user($request))->sentence);
    }

    public function cancel(Request $request, Thread $thread): RedirectResponse
    {
        return $this->respond($thread, fn (): string => $this->actions->cancel($thread, $this->user($request))->sentence);
    }

    public function move(Request $request, Thread $thread): RedirectResponse
    {
        $head = $this->guard($thread, fn () => $this->actions->guardMove($thread));

        if ($head instanceof RedirectResponse) {
            return $head;
        }
        $repo = $request->validate(ThreadTaskActions::MOVE_RULES)['repo'];

        return $this->respond($thread, function () use ($thread, $head, $repo): RedirectResponse {
            $moved = $this->actions->move($head, $thread, $repo);
            $redirect = redirect()->route('threads.show', $moved['thread']);

            return $moved['outcome']->sentence === '' ? $redirect : $redirect->with('success', $moved['outcome']->sentence);
        });
    }

    public function requestReview(Request $request, Thread $thread, ThreadAccess $access): RedirectResponse
    {
        return $this->respond($thread, function () use ($request, $thread, $access): RedirectResponse {
            $outcome = $this->actions->requestReview($thread);
            $reviewThread = $outcome->isNewReview ? $outcome->task->thread : null;
            $target = $reviewThread !== null && $access->canSee($request->user(), $reviewThread) ? $reviewThread : $thread;

            return redirect()->route('threads.show', $target)->with($outcome->isError ? 'error' : 'success', $outcome->sentence);
        });
    }

    public function retryRender(Thread $thread): RedirectResponse
    {
        return $this->respond($thread, fn (): string => $this->actions->retryRender($thread)->sentence);
    }

    public function answerClarification(StoreThreadClarificationAnswersRequest $request, Thread $thread, ClarificationAnswerSubmitter $submitter): RedirectResponse
    {
        return $this->respond($thread, function () use ($request, $submitter): string {
            $answeredBy = (string) ($request->user()->name ?? 'Someone');
            $submitted = $request->wantsClaudeToDecide()
                ? $submitter->decide($request->head(), $answeredBy, 'dashboard')
                : $submitter->submit($request->head(), $request->answers(), $request->validated('note'), $answeredBy, 'dashboard');

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
