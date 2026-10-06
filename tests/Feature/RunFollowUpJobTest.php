<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
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

function followUpArrange(AgentRunResult $result, array $taskState = []): array
{
    Queue::fake();
    $fake = (new FakeAgentRunner)->queueResult($result);
    app()->instance(AgentRunner::class, $fake);
    $sandbox = new FakeSandboxManager;
    app()->instance(IncusSandboxManager::class, $sandbox);
    Process::fake(['*' => Process::result('')]);
    Repository::factory()->create(['slug' => 'followup-repo', 'path' => '/home/yak/repos/followup-repo', 'ci_system' => 'none']);
    $root = YakTask::factory()->create(['repo' => 'followup-repo', 'status' => TaskStatus::Success, 'started_at' => now()->subHour()]);
    $child = YakTask::factory()->create($taskState + [
        'parent_task_id' => $root->id,
        'repo' => 'followup-repo',
        'status' => TaskStatus::Pending,
        'branch_name' => 'yak/some-branch',
        'session_id' => 'sess_followup',
    ]);

    return [$child, $fake, $sandbox];
}

test('a follow-up that asks parks the child task and pushes nothing', function () {
    [$child, $fake, $sandbox] = followUpArrange(new AgentRunResult(
        sessionId: 'sess_fu_q', resultSummary: 'Need a call', costUsd: 0.1, numTurns: 1, durationMs: 10,
        isError: false, rawOutput: '{}', clarificationQuestions: [sampleQuestion('scope')],
    ));

    (new RunFollowUpJob($child))->handle($fake);

    expect($child->fresh()->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($sandbox->commandsMatching('git push'))->toBe([]);
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

test('a follow-up resumed with answers uses the answers prompt', function () {
    [$child, $fake] = followUpArrange(new AgentRunResult(
        sessionId: 'sess_fu_r', resultSummary: 'Done', costUsd: 0.1, numTurns: 1, durationMs: 10, isError: false, rawOutput: '{}',
    ), ['status' => TaskStatus::AwaitingClarification, 'session_id' => 'sess_fu_first']);
    $child->update(['clarification_rounds' => YakTask::factory()->withClarificationQuestions()->make()->clarification_rounds]);
    $child->recordClarificationAnswers(['scope' => ['choices' => ['Small'], 'other' => null]], null, 'Michele');
    $child->update(['status' => TaskStatus::Pending]);

    (new RunFollowUpJob($child->fresh()))->handle($fake);

    expect($fake->lastCall()->prompt)->toContain("Q: Which scope?\nA: Small")
        ->and($fake->lastCall()->resumeSessionId)->toBe('sess_fu_first');
});

test('a follow-up on its third round does not park and drops the clarification block', function () {
    [$child, $fake] = followUpArrange(new AgentRunResult(
        sessionId: 'sess_fu_3', resultSummary: "## What changed in this run\n\nDid it.\n\n```clarification\n{\"questions\": []}\n```", costUsd: 0.1, numTurns: 1, durationMs: 10,
        isError: false, rawOutput: '{}', clarificationQuestions: [sampleQuestion('again')],
    ));
    foreach (range(1, 3) as $round) {
        $child->recordClarificationRound([sampleQuestion("q{$round}")], 'Summary');
        $child->recordClarificationAnswers(["q{$round}" => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');
        $child->markClarificationAnswersConsumed();
    }

    (new RunFollowUpJob($child->fresh()))->handle($fake);

    $child->refresh();
    expect($child->status)->not->toBe(TaskStatus::AwaitingClarification)
        ->and($child->result_summary)->not->toContain('```clarification');
});
