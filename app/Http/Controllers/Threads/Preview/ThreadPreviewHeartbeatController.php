<?php

namespace App\Http\Controllers\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sent by the open Preview panel so a viewed preview keeps its sandbox awake. */
class ThreadPreviewHeartbeatController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadAccess $access, ThreadPreviewActivity $activity): JsonResponse
    {
        abort_unless($access->canSee($request->user(), $thread), 404);

        $activity->record($thread);

        return response()->json(['ok' => true]);
    }
}
