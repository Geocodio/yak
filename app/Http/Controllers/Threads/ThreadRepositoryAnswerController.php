<?php

namespace App\Http\Controllers\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Models\Repository;
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

            /** @var list<string> $options */
            $options = array_values((array) ($head->clarification_options ?: Repository::where('is_active', true)->pluck('slug')->all()));

            if (! in_array($repo, $options, true)) {
                throw ThreadActionRefused::because(ThreadActionRefused::REPOSITORY_NOT_OFFERED, 'Pick one of the repositories Yak offered.');
            }

            RepoClarificationResolver::resolve($head, $repo);

            return "Starting in {$repo}.";
        });
    }
}
