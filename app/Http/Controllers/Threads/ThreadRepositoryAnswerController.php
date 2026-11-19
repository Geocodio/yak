<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Models\Thread;
use App\Services\Threads\ThreadTaskActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The answer to "Which repository is this for?": attaches the chosen repository to the thread and starts the run.
 */
class ThreadRepositoryAnswerController extends Controller
{
    use RespondsToThreadActions;

    public function __invoke(Request $request, Thread $thread, ThreadTaskActions $actions): RedirectResponse
    {
        $head = $this->guard($thread, fn () => $actions->guardRepositoryAnswer($thread));

        if ($head instanceof RedirectResponse) {
            return $head;
        }
        $repo = $request->validate(ThreadTaskActions::REPOSITORY_ANSWER_RULES)['repo'];

        return $this->respond($thread, function () use ($actions, $thread, $head, $repo): string {
            $actions->answerRepository($thread, $head, $repo, mayPickAnyActive: true);

            return "Starting in {$repo}.";
        });
    }
}
