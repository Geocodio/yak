<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\StackData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The app's stack routes: the Overview reads the stack from a root or from any of its sub-threads.
 */
class StackController extends Controller
{
    public function show(Request $request, Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(StackData::for($thread, $request->user()));
    }
}
