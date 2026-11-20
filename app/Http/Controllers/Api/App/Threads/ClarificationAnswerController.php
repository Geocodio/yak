<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreThreadClarificationAnswersRequest;
use App\Models\Thread;
use App\Services\ClarificationAnswerSubmitter;
use Illuminate\Http\JsonResponse;

class ClarificationAnswerController extends Controller
{
    public function __invoke(StoreThreadClarificationAnswersRequest $request, Thread $thread, ClarificationAnswerSubmitter $submitter): JsonResponse
    {
        $answeredBy = (string) ($request->user()->name ?? 'Someone');
        $submitted = $request->wantsClaudeToDecide()
            ? $submitter->decide($request->head(), $answeredBy, 'app')
            : $submitter->submit($request->head(), $request->answers(), $request->validated('note'), $answeredBy, 'app');

        if (! $submitted) {
            throw ThreadActionRefused::because(ThreadActionRefused::QUESTION_CLOSED, StoreThreadClarificationAnswersRequest::CLOSED_SENTENCE);
        }

        return response()->json(['status' => 'sent']);
    }
}
