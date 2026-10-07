<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\ResponsiblePersonResolver;
use App\Services\TaskLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StoreTaskController extends Controller
{
    public function __invoke(StoreTaskRequest $request, AgentJobDispatcher $dispatcher): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        $authorName = $user?->name;
        $resolver = app(ResponsiblePersonResolver::class);

        $task = YakTask::create([
            'source' => 'dashboard',
            'repo' => $validated['repo'],
            'external_id' => 'DASH-' . Str::upper(Str::random(8)),
            'description' => trim((string) $validated['description']),
            'mode' => $validated['mode'],
            'author_name' => $authorName,
            'responsible_name' => $resolver->resolve(null, $authorName, $validated['repo']),
            'started_by_user_id' => $user?->id,
            'responsible_user_id' => $resolver->resolveUser(null, $user, $validated['repo'])?->id,
        ]);

        TaskAttachment::storeFromRequest($request, ['yak_task_id' => $task->id]);

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
