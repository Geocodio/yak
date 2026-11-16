<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadDiffData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The Changes panel's switch: the diff of one sub-thread of this stack.
 */
class ChildDiffController extends Controller
{
    public function __invoke(Thread $thread, Thread $child): JsonResponse
    {
        // A hidden thread, or a thread of another stack, answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread) && Gate::allows('view', $child), 404);
        abort_unless($child->isChild() && $child->root_thread_id === ($thread->root_thread_id ?? $thread->id), 404);

        return response()->json(ThreadDiffData::build($child));
    }
}
