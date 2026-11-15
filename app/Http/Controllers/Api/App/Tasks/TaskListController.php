<?php

namespace App\Http\Controllers\Api\App\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ListAppTasksRequest;
use App\Http\Resources\AppTaskListData;
use Illuminate\Http\JsonResponse;

class TaskListController extends Controller
{
    public function __invoke(ListAppTasksRequest $request): JsonResponse
    {
        return response()->json(AppTaskListData::for($request->filters(), $request->page()));
    }
}
