<?php

namespace App\Actions;

use App\Enums\TaskMode;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Str;

/**
 * Creates and dispatches a setup task for a repository, used both when a
 * repository is first created and when a user re-runs setup from the edit
 * page.
 */
class DispatchRepositorySetupTask
{
    public function __invoke(Repository $repository): YakTask
    {
        $task = YakTask::create([
            'repo' => $repository->slug,
            'external_id' => 'setup-' . Str::random(8),
            'mode' => TaskMode::Setup,
            'description' => "Setup repository: {$repository->name}",
            'source' => 'dashboard',
        ]);

        $repository->update([
            'setup_task_id' => $task->id,
            'setup_status' => 'pending',
        ]);

        SetupYakJob::dispatch($task);

        return $task;
    }
}
