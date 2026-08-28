<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/**
 * The thread hides any run with no started_at (see ThreadBuilder), so a
 * follow-up that never stamps it is invisible in the conversation whether
 * it succeeds or fails.
 */
test('a follow-up run stamps started_at when the worker picks it up', function () {
    Queue::fake();

    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_followup',
        resultSummary: 'Committed it as a draft.',
        costUsd: 0.05,
        numTurns: 3,
        durationMs: 178438,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'followup-repo', 'path' => '/home/yak/repos/followup-repo', 'ci_system' => 'none']);

    $root = YakTask::factory()->create(['repo' => 'followup-repo', 'status' => TaskStatus::Success, 'started_at' => now()->subHour()]);
    $task = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'repo' => 'followup-repo',
        'status' => TaskStatus::Pending,
        'branch_name' => 'yak/some-branch',
        'session_id' => 'sess_followup',
        'started_at' => null,
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    expect($task->fresh()->started_at)->not->toBeNull();
});

test('a follow-up that fails before reaching the agent still stamps started_at', function () {
    Queue::fake();

    $fake = new FakeAgentRunner;
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'followup-repo-2', 'path' => '/home/yak/repos/followup-repo-2']);

    $task = YakTask::factory()->create([
        'repo' => 'followup-repo-2',
        'status' => TaskStatus::Pending,
        'branch_name' => null,
        'started_at' => null,
    ]);

    (new RunFollowUpJob($task))->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->started_at)->not->toBeNull();
});
