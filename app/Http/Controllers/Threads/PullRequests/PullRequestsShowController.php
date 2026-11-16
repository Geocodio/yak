<?php

namespace App\Http\Controllers\Threads\PullRequests;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\PullRequests\ThreadPullRequestsPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Pull requests panel's data.
 */
class PullRequestsShowController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadPullRequestsPanel $panel): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json($panel->build($thread, $request->user()));
    }
}
