<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ListThreadsRequest;
use App\Http\Resources\AppThreadListData;
use Illuminate\Http\JsonResponse;

class ThreadListController extends Controller
{
    public function __invoke(ListThreadsRequest $request): JsonResponse
    {
        return response()->json(AppThreadListData::for(
            $request->user() ?? abort(401),
            $request->listFilter(),
            $request->searchText(),
            $request->sourceKeys(),
            $request->starterKeys(),
        ));
    }
}
