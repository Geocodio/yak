<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Exceptions\ThreadSandboxNotDestroyed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StarThreadRequest;
use App\Http\Resources\AppThreadListData;
use App\Models\Thread;
use App\Models\ThreadStar;
use App\Services\Threads\ThreadCloser;
use App\Services\Threads\ThreadDeleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Star, close and delete a thread: the web's actions, with the same visibility and drive rules.
 * Each answers with the thread's list row.
 */
class ThreadManagementController extends Controller
{
    public function star(StarThreadRequest $request, Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $user = $request->user() ?? abort(401);
        $attributes = ['user_id' => $user->id, 'thread_id' => $thread->id];

        if ($request->isStarred()) {
            ThreadStar::query()->createOrFirst($attributes);
        } else {
            ThreadStar::query()->where($attributes)->delete();
        }

        return $this->row($request, $thread);
    }

    public function close(Request $request, Thread $thread, ThreadCloser $closer): JsonResponse
    {
        $closer->close($thread, 'manual');

        return $this->row($request, $thread->refresh());
    }

    public function destroy(Thread $thread, ThreadDeleter $deleter): JsonResponse
    {
        try {
            $deleter->delete($thread);
        } catch (ThreadSandboxNotDestroyed) {
            throw ThreadActionRefused::because(ThreadActionRefused::SANDBOX_UNAVAILABLE, ThreadSandboxNotDestroyed::SENTENCE);
        }

        return response()->json(['id' => $thread->public_id, 'status' => 'deleted']);
    }

    private function row(Request $request, Thread $thread): JsonResponse
    {
        return response()->json(AppThreadListData::rowFor($request->user() ?? abort(401), $thread) ?? abort(404));
    }
}
