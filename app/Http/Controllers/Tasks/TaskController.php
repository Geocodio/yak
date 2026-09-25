<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogData;
use App\Http\Resources\TaskDetailData;
use App\Http\Resources\TranscriptData;
use App\Models\TaskLog;
use App\Models\YakTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function show(Request $request, YakTask $task): Response|RedirectResponse
    {
        if ($task->parent_task_id !== null) {
            $root = $task->conversation()->first();

            return redirect(route('tasks.show', $root) . '#turn-' . $task->id);
        }

        $conversation = $task->conversation();
        $focusedRun = TaskDetailData::resolveFocusedRun($conversation, $request->integer('run') ?: null);

        $data = TaskDetailData::build($task, $request);
        $attempt = $data['task']['attempt'];

        $entryFor = function () use ($request, $conversation): ?array {
            $logId = TaskDetailData::resolveTranscriptLogId($request, $conversation);
            $log = $logId !== null ? TaskLog::find($logId) : null;

            return $log !== null ? TranscriptData::entry($log) : null;
        };

        return Inertia::render('Tasks/Show', [
            ...$data,
            'activityOlder' => Inertia::optional(fn () => $request->integer('before')
                ? ActivityLogData::before($focusedRun, $attempt, $request->integer('before'))
                : []),
            // `after=0` is a valid cursor: a run whose window started empty
            // asks for its first rows that way.
            'activityTail' => Inertia::optional(fn () => $request->has('after')
                ? ActivityLogData::after($focusedRun, $attempt, (int) $request->input('after'))
                : []),
            'transcriptEntry' => $request->has('log') && ! $request->header('X-Inertia-Partial-Component')
                ? $entryFor()
                : Inertia::optional($entryFor),
        ]);
    }
}
