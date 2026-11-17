<?php

namespace App\Http\Controllers\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Models\Thread;
use App\Services\RepoClarificationResolver;
use App\Services\Threads\ThreadTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The answer to "Which repository is this for?": attaches the chosen repository to the thread and starts the run.
 */
class ThreadRepositoryAnswerController extends Controller
{
    use RespondsToThreadActions;

    public function __invoke(Request $request, Thread $thread): RedirectResponse
    {
        $head = ThreadTasks::head($thread) ?? $this->nothingTo('answer');
        $repo = $request->validate(['repo' => ['required', 'string']])['repo'];

        return $this->respond($thread, function () use ($thread, $head, $repo): string {
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

            return "Starting in {$repo}.";
        });
    }
}
