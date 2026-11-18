<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadCardsData;
use App\Http\Resources\ThreadQueuedMessagesData;
use App\Http\Resources\ThreadRunLogData;
use App\Http\Resources\ThreadShowData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\ThreadTasks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ThreadController extends Controller
{
    public function show(Request $request, Thread $thread): Response
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $entryId = $request->query('log');

        return Inertia::render('Threads/Show', [
            ...ThreadShowData::build($thread, $request->user() ?? abort(401)),
            'turnAnchors' => $thread->is_legacy ? [] : ThreadShowData::turnAnchors($thread),
            'isExpiredCoOwnerStop' => ! $thread->is_legacy && ($head = ThreadTasks::head($thread)) !== null && ThreadCardsData::isExpiredCoOwnerStop($head),
            'queuedMessages' => ThreadQueuedMessagesData::for($thread, $request->user()),
            'runs' => ThreadRunLogData::runs($thread),
            'openLog' => (is_string($entryId) && ctype_digit($entryId) ? ThreadRunLogData::locate($thread, (int) $entryId) : null) ?? $this->openRun($request, $thread),
        ]);
    }

    /**
     * A `?run=` address opens the full log of that run of the thread, on its first step.
     *
     * @return array{runId: string, entryId: null}|null
     */
    private function openRun(Request $request, Thread $thread): ?array
    {
        $runId = $request->query('run');

        if ($thread->is_legacy || ! is_string($runId) || ! ctype_digit($runId) || ! TaskRun::query()->where('thread_id', $thread->id)->whereKey((int) $runId)->exists()) {
            return null;
        }

        return ['runId' => $runId, 'entryId' => null];
    }
}
