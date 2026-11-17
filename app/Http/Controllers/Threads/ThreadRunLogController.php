<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadRunLogData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The full log of one run for the "Full log" overlay, a page of 50 steps after a cursor. A hidden thread and
 * a run of another thread answer exactly like a missing one.
 */
class ThreadRunLogController extends Controller
{
    public function show(Request $request, Thread $thread, TaskRun $run): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread) && $run->thread_id === $thread->id, 404);

        return response()->json(ThreadRunLogData::forRun($thread, $run, max(0, $request->integer('cursor')), $this->search($request)));
    }

    public function showTask(Request $request, Thread $thread, YakTask $task): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread) && ThreadRunLogData::belongsToThread($thread, $task), 404);

        return response()->json(ThreadRunLogData::forTask($task, max(0, $request->integer('cursor')), $this->search($request)));
    }

    private function search(Request $request): ?string
    {
        $search = $request->query('search');

        return is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 200) : null;
    }
}
