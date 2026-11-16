<?php

namespace App\Http\Controllers\Api\App\Tasks;

use App\DataTransferObjects\TaskActionOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\RerouteAppTaskRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\TaskActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskActionController extends Controller
{
    public function retry(YakTask $task, TaskActions $actions): JsonResponse
    {
        return $this->status($actions->retry($task, isTimeBounded: true));
    }

    public function cancel(Request $request, YakTask $task, TaskActions $actions): JsonResponse
    {
        /** @var User $canceller */
        $canceller = $request->user();

        return $this->status($actions->cancel($task, $canceller));
    }

    public function rerunReview(YakTask $task, TaskActions $actions): JsonResponse
    {
        return $this->status($actions->rerunReview($task, isTimeBounded: true));
    }

    public function retryRender(YakTask $task, TaskActions $actions): JsonResponse
    {
        return $this->status($actions->retryRender($task));
    }

    public function reroute(RerouteAppTaskRequest $request, YakTask $task, TaskActions $actions): JsonResponse
    {
        return $this->status($actions->reroute($task, $request->validated('repo')));
    }

    private function status(TaskActionOutcome $outcome): JsonResponse
    {
        return response()->json(['status' => $outcome->status]);
    }
}
