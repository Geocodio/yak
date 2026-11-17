<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Api\App\Threads\Concerns\AnswersThreadActions;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\RepoClarificationResolver;
use App\Services\Threads\ThreadTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The answer to "Which repository is this for?": attaches the chosen repository to the thread and starts the run.
 */
class ThreadRepositoryAnswerController extends Controller
{
    use AnswersThreadActions;

    public function __invoke(Request $request, Thread $thread): JsonResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('answer');
        $repo = $request->validate(['repo' => ['required', 'string', 'max:255']])['repo'];

        $this->refuseClosed($thread);

        if ($thread->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_ALREADY_PICKED, 'This thread already has a repository.');
        }

        if (! in_array($repo, RepoClarificationResolver::offeredOptions($head), true)) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_NOT_OFFERED, 'Pick one of the repositories Yak offered.');
        }

        if (! RepoClarificationResolver::start($head, $repo) || ! $thread->fresh()?->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_NOT_OFFERED, 'Yak could not start in that repository. Pick another one.');
        }

        return $this->answer($request, $thread, 'started');
    }
}
