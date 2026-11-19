<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Api\App\Threads\Concerns\AnswersThreadActions;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTaskActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The task actions of the thread, for the app. The shared {@see ThreadTaskActions} guards and runs each one; this controller turns the result into the answer of the matching app task route plus the refreshed thread show.
 */
class ThreadTaskActionController extends Controller
{
    use AnswersThreadActions;

    public function __construct(private readonly ThreadTaskActions $actions) {}

    public function retry(Request $request, Thread $thread): JsonResponse
    {
        return $this->answer($request, $thread, $this->actions->retry($thread, isTimeBounded: true)->status);
    }

    public function setUpRepositoryAndContinue(Request $request, Thread $thread): JsonResponse
    {
        return $this->answer($request, $thread, $this->actions->setUpRepositoryAndContinue($thread, $this->user($request))->status);
    }

    public function cancel(Request $request, Thread $thread): JsonResponse
    {
        return $this->answer($request, $thread, $this->actions->cancel($thread, $this->user($request))->status);
    }

    public function move(Request $request, Thread $thread, ThreadAccess $access): JsonResponse
    {
        $head = $this->actions->guardMove($thread);
        $repo = $request->validate(ThreadTaskActions::MOVE_RULES)['repo'];
        $moved = $this->actions->move($head, $thread, $repo);
        $shown = $access->canSee($this->user($request), $moved['thread']) ? $moved['thread'] : $thread;

        return $this->answer($request, $shown, $moved['outcome']->status);
    }

    public function requestReview(Request $request, Thread $thread): JsonResponse
    {
        $outcome = $this->actions->requestReview($thread);

        if (! $outcome->isNewReview) {
            throw ThreadActionRefused::because(ThreadActionRefused::REVIEW_NOT_REQUESTED, $outcome->sentence);
        }

        return $this->answer($request, $thread, 'queued');
    }

    public function retryRender(Request $request, Thread $thread): JsonResponse
    {
        return $this->answer($request, $thread, $this->actions->retryRender($thread)->status);
    }

    private function user(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
