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
use Illuminate\Support\Facades\RateLimiter;

/**
 * The full log of one run for the "Full log" overlay, a page of 50 steps after a cursor. A hidden thread and
 * a run of another thread answer exactly like a missing one.
 */
class ThreadRunLogController extends Controller
{
    private const int SEARCHES_PER_MINUTE = 120;

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

    /**
     * A search reads the whole run, so a viewer may ask for 120 of them a minute (the overlay waits 250 ms after a
     * keystroke and asks again every few seconds on a live run).
     */
    private function search(Request $request): ?string
    {
        $search = $request->query('search');
        $term = is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 200) : null;

        if ($term !== null) {
            $key = 'run-log-search:' . ($request->user() ?? abort(401))->id;
            abort_if(RateLimiter::tooManyAttempts($key, self::SEARCHES_PER_MINUTE), 429);
            RateLimiter::hit($key, 60);
        }

        return $term;
    }
}
