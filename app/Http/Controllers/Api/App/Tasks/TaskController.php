<?php

namespace App\Http\Controllers\Api\App\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppTaskData;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function show(Request $request, YakTask $task): JsonResponse
    {
        return response()->json(AppTaskData::for($task, $request));
    }
}
