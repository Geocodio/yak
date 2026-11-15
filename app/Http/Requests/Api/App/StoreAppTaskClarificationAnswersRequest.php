<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Tasks\StoreClarificationAnswersRequest;

/**
 * Same limits as the thread route: at most 10 answers, 500 characters per choice, 2000 per free text and note.
 */
class StoreAppTaskClarificationAnswersRequest extends StoreClarificationAnswersRequest {}
