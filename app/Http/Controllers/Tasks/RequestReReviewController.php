<?php

namespace App\Http\Controllers\Tasks;

use App\Actions\RequestReReview;
use App\Http\Controllers\Controller;
use App\Models\YakTask;
use Illuminate\Http\RedirectResponse;

class RequestReReviewController extends Controller
{
    public function __invoke(YakTask $task, RequestReReview $requestReReview): RedirectResponse
    {
        $outcome = $requestReReview($task);

        return redirect()->route('tasks.show', $outcome->task)->with($outcome->isError ? 'error' : 'success', $outcome->sentence);
    }
}
