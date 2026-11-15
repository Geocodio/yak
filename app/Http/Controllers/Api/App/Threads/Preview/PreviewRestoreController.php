<?php

namespace App\Http\Controllers\Api\App\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewRestore;
use Illuminate\Http\JsonResponse;

/** Restores a destroyed preview sandbox; a repeat while one runs answers the same. */
class PreviewRestoreController extends Controller
{
    public function __invoke(Thread $thread, ThreadPreviewRestore $restore): JsonResponse
    {
        $restore->begin($thread);

        return response()->json(['status' => 'restoring'], 202);
    }
}
