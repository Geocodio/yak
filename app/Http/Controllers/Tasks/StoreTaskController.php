<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\TaskLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StoreTaskController extends Controller
{
    public function __invoke(StoreTaskRequest $request, AgentJobDispatcher $dispatcher): RedirectResponse
    {
        $validated = $request->validated();

        $task = YakTask::create([
            'source' => 'dashboard',
            'repo' => $validated['repo'],
            'external_id' => 'DASH-' . Str::upper(Str::random(8)),
            'description' => trim((string) $validated['description']),
            'mode' => $validated['mode'],
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'dashboard', 'repo' => $task->repo]);

        /** @var TaskMode $mode */
        $mode = $task->mode;

        if ($mode === TaskMode::Research) {
            $dispatcher->dispatch($task, ResearchYakJob::class);
        } else {
            $dispatcher->dispatch($task, RunYakJob::class);
        }

        return redirect()->route('tasks.show', $task)->with('success', 'Task created.');
    }
}
