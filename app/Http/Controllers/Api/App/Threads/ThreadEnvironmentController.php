<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadEnvironmentData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Overview's environment: sandbox, branch, pull request, account and repositories.
 */
class ThreadEnvironmentController extends Controller
{
    public function show(Request $request, Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(ThreadEnvironmentData::build($thread, $request->user()));
    }
}
