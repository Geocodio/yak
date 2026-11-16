<?php

namespace App\Http\Controllers\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewStatus;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadPreviewStatusController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadAccess $access, ThreadPreviewStatus $status): JsonResponse
    {
        abort_unless($access->canSee($request->user(), $thread), 404);

        return response()->json($status->for($thread));
    }
}
