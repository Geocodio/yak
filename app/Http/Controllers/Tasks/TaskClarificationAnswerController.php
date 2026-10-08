<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreClarificationAnswersRequest;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use Illuminate\Http\RedirectResponse;

class TaskClarificationAnswerController extends Controller
{
    public function __invoke(StoreClarificationAnswersRequest $request, YakTask $task, ClarificationAnswerSubmitter $submitter): RedirectResponse
    {
        $submitted = $submitter->submit(
            $request->head(),
            $request->answers(),
            $request->validated('note'),
            (string) ($request->user()->name ?? 'Someone'),
            'dashboard',
        );

        return redirect()->route('tasks.show', $task)->with(
            $submitted ? 'success' : 'error',
            $submitted ? 'Sent to Yak.' : 'These questions were already answered.',
        );
    }
}
