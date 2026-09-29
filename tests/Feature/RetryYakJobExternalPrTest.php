<?php

use App\Contracts\AgentRunner;
use App\Enums\TaskStatus;
use App\Jobs\RetryYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

it('refuses to retry a task on a human-authored PR', function () {
    $sandbox = new FakeSandboxManager;
    app()->instance(IncusSandboxManager::class, $sandbox);
    $agent = new FakeAgentRunner;
    app()->instance(AgentRunner::class, $agent);

    Repository::factory()->create(['slug' => 'web']);
    $task = YakTask::factory()->create([
        'repo' => 'web', 'status' => TaskStatus::Retrying, 'branch_name' => 'feature/warnings', 'targets_external_pr' => true,
    ]);

    (new RetryYakJob($task, null))->handle($agent);

    expect($sandbox->createdContainers)->toBe([])
        ->and($task->fresh()->status)->toBe(TaskStatus::Failed);
});
