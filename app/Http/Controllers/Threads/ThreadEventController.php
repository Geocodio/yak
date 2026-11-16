<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadEventFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Catch-up for a browser that missed realtime events or received a pointer. A hidden thread
 * answers exactly like a missing one.
 */
class ThreadEventController extends Controller
{
    public const MAX_LIMIT = 500;

    public function index(Request $request, Thread $thread, ThreadEventFeed $feed): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $after = max(0, $request->integer('after'));
        $limit = min(self::MAX_LIMIT, max(1, $request->integer('limit', self::MAX_LIMIT)));

        return response()->json($feed->since($thread, $after, $limit));
    }

    public function show(Thread $thread, int $sequence, ThreadEventFeed $feed): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json($feed->one($thread, $sequence));
    }
}
