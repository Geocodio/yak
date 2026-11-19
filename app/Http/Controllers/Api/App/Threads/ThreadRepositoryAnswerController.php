<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Api\App\Threads\Concerns\AnswersThreadActions;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadTaskActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The answer to "Which repository is this for?": attaches the chosen repository to the thread and starts the run.
 */
class ThreadRepositoryAnswerController extends Controller
{
    use AnswersThreadActions;

    public function __invoke(Request $request, Thread $thread, ThreadTaskActions $actions): JsonResponse
    {
        $head = $actions->guardRepositoryAnswer($thread);
        $repo = $request->validate(ThreadTaskActions::REPOSITORY_ANSWER_RULES)['repo'];
        $actions->answerRepository($thread, $head, $repo);

        return $this->answer($request, $thread, 'started');
    }
}
