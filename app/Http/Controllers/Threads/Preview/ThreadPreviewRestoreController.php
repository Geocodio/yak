<?php

namespace App\Http\Controllers\Threads\Preview;

use App\Enums\ThreadOwnerSide;
use App\Http\Controllers\Controller;
use App\Jobs\Threads\RestoreThreadPreviewJob;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ThreadPreviewRestoreController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadAccess $access): JsonResponse
    {
        abort_unless($access->canSee($request->user(), $thread), 404);
        abort_unless($access->canDrive($request->user(), $thread), 403);

        if (! $thread->isOpen()) {
            return response()->json(['message' => 'This thread is closed.'], 422);
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return response()->json(['message' => 'This thread runs on a local machine.'], 422);
        }

        if (Cache::add("thread-preview:restore:{$thread->id}", true, 300)) {
            RestoreThreadPreviewJob::dispatch($thread);
        }

        return response()->json(['ok' => true], 202);
    }
}
