<?php

use App\Enums\TaskStatus;
use App\Jobs\RetryYakJob;
use App\Models\Repository;
use App\Models\YakTask;

it('refuses to retry a task on a human-authored PR', function () {
    Repository::factory()->create(['slug' => 'web']);
    $task = YakTask::factory()->create([
        'repo' => 'web', 'status' => TaskStatus::Retrying, 'branch_name' => 'feature/warnings', 'targets_external_pr' => true,
    ]);

    (new RetryYakJob($task, null))->handle();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->thread_id)->toBeNull();
});
