<?php

namespace App\Http\Controllers\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Starts a thread's preview wake from the dashboard, so no hidden frame has to load the preview host. */
class ThreadPreviewWarmController extends Controller
{
    private const THROTTLE_SECONDS = 5;

    public function __invoke(Request $request, Thread $thread, ThreadAccess $access, ThreadPreviewWaker $waker, ThreadPreviewActivity $activity): JsonResponse
    {
        abort_unless($access->canSee($request->user(), $thread), 404);

        if (! Cache::add("thread-preview:warm:{$thread->id}", true, self::THROTTLE_SECONDS)) {
            return response()->json(['state' => 'waking']);
        }

        $activity->record($thread);

        return response()->json(['state' => $waker->wake($thread, 0)->state]);
    }
}
