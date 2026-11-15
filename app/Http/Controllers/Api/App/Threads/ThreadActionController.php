<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadRecovery;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadActionController extends Controller
{
    public function stop(Thread $thread, ThreadTurns $turns): JsonResponse
    {
        $turns->stop($thread);

        return response()->json(['status' => 'stopping']);
    }

    public function continueTurn(Request $request, Thread $thread, ThreadRecovery $recovery): JsonResponse
    {
        $recovery->continueTurn($thread, $request->user());

        return response()->json(['status' => 'continued']);
    }

    public function retryRestore(Thread $thread, ThreadRecovery $recovery): JsonResponse
    {
        $recovery->retryRestore($thread);

        return response()->json(['status' => 'restoring']);
    }
}
