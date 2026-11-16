<?php

namespace App\Http\Controllers\Threads\Preview;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewRestore;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadPreviewRestoreController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadAccess $access, ThreadPreviewRestore $restore): JsonResponse
    {
        abort_unless($access->canSee($request->user(), $thread), 404);
        abort_unless($access->canDrive($request->user(), $thread), 403);

        try {
            $restore->begin($thread);
        } catch (ThreadActionRefused $refusal) {
            return response()->json(['message' => $refusal->getMessage()], 422);
        }

        return response()->json(['ok' => true], 202);
    }
}
