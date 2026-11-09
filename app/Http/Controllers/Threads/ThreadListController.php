<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadListStatus;
use App\Http\Controllers\Controller;
use App\Services\Threads\ThreadList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => ['sometimes', 'in:mine,everyone'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'nullable', 'in:running,blocked,paused,stopped'],
        ]);

        return response()->json(ThreadList::for(
            $request->user() ?? abort(401),
            $request->input('filter', 'mine'),
            $request->input('search'),
            ThreadListStatus::tryFrom((string) $request->input('status')),
        ));
    }
}
