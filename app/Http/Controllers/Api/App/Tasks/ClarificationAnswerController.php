<?php

namespace App\Http\Controllers\Api\App\Tasks;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppTaskClarificationAnswersRequest;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use Illuminate\Http\JsonResponse;

class ClarificationAnswerController extends Controller
{
    public function __invoke(StoreAppTaskClarificationAnswersRequest $request, YakTask $task, ClarificationAnswerSubmitter $submitter): JsonResponse
    {
        $submitted = $submitter->submit(
            $request->head(),
            $request->answers(),
            $request->validated('note'),
            (string) ($request->user()->name ?? 'Someone'),
            'app',
        );

        if (! $submitted) {
            throw ThreadActionRefused::because(ThreadActionRefused::QUESTION_CLOSED, 'These questions were already answered.');
        }

        return response()->json(['status' => 'sent']);
    }
}
