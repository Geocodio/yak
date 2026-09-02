<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskDetailData;
use App\Http\Resources\TranscriptData;
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

        $transcriptFor = fn () => TranscriptData::for($focusedRun, $attempt);

        return Inertia::render('Tasks/Show', [
            ...$data,
            'transcript' => $request->has('log') ? $transcriptFor() : Inertia::optional($transcriptFor),
        ]);
    }
}
