<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ListThreadEventsRequest;
use App\Models\Thread;
use App\Services\Threads\ThreadEventFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ThreadEventController extends Controller
{
    public function index(ListThreadEventsRequest $request, Thread $thread, ThreadEventFeed $feed): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json($feed->since($thread, $request->afterSequence(), $request->eventLimit()));
    }

    public function show(Thread $thread, int $sequence, ThreadEventFeed $feed): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json($feed->one($thread, $sequence));
    }
}
