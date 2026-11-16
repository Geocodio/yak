<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadDiffData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ThreadDiffController extends Controller
{
    public function show(Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(ThreadDiffData::build($thread));
    }

    public function showFile(Thread $thread, int $file): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(ThreadDiffData::file($thread, $file));
    }
}
