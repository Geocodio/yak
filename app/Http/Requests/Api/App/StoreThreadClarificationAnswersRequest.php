<?php

namespace App\Http\Requests\Api\App;

use App\Exceptions\ThreadActionRefused;
use App\Http\Requests\Concerns\AuthorizesThreadDriving;
use App\Http\Requests\Tasks\StoreClarificationAnswersRequest;
use App\Models\Thread;
use App\Models\YakTask;

class StoreThreadClarificationAnswersRequest extends StoreClarificationAnswersRequest
{
    use AuthorizesThreadDriving;

    public const CLOSED_SENTENCE = 'These questions were already answered or have expired. Reload the thread to see where it stands.';

    /**
     * The head task of the thread. A thread with no task has no open question.
     */
    public function head(): YakTask
    {
        /** @var Thread $thread */
        $thread = $this->route('thread');

        return $thread->headTask() ?? throw ThreadActionRefused::because(ThreadActionRefused::QUESTION_CLOSED, self::CLOSED_SENTENCE);
    }
}
