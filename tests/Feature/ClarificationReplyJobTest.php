<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAgentRunner;

/*
|--------------------------------------------------------------------------
| Successful Resume & Implementation
|--------------------------------------------------------------------------
*/

test('successful clarification reply transitions task to awaiting_ci and force pushes branch', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_resumed',
        resultSummary: 'Implemented the chosen interpretation',
        costUsd: 1.50,
        numTurns: 8,
        durationMs: 60000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'git push *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'test-repo',
        'session_id' => 'sess_original',
        'branch_name' => 'yak/ISSUE-100',
        'cost_usd' => 2.00,
        'num_turns' => 15,
        'duration_ms' => 120000,
    ]);

    $job = new ClarificationReplyJob($task, 'Option B - Fix the API endpoint');
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_resumed')
        ->and($task->result_summary)->toBe('Implemented the chosen interpretation')
        ->and((float) $task->cost_usd)->toBe(3.50)
        ->and($task->num_turns)->toBe(23)
        ->and($task->duration_ms)->toBe(180000);

    Process::assertRan(fn ($process) => str_contains($process->command, 'git push --force'));
});

/*
|--------------------------------------------------------------------------
| Status Transitions
|--------------------------------------------------------------------------
*/

test('transitions from awaiting_clarification to running during execution', function () {
    $statuses = [];

    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'st-repo', 'path' => '/home/yak/repos/st-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'st-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/ST-1',
    ]);

    YakTask::updating(function (YakTask $model) use (&$statuses) {
        $statuses[] = $model->status;
    });

    $job = new ClarificationReplyJob($task, 'Option A');
    $job->handle($fake);

    expect($statuses[0])->toBe(TaskStatus::Running)
        ->and($statuses)->toContain(TaskStatus::AwaitingCi);
});

/*
|--------------------------------------------------------------------------
| Claude --resume Flag
|--------------------------------------------------------------------------
*/

test('invokes claude with --resume flag and session_id', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_resumed',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'res-repo', 'path' => '/home/yak/repos/res-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'res-repo',
        'session_id' => 'sess_original_123',
        'branch_name' => 'yak/RES-1',
    ]);

    $job = new ClarificationReplyJob($task, 'Option A - The first approach');
    $job->handle($fake);

    expect($fake->lastCall()->resumeSessionId)->toBe('sess_original_123');
});

test('claude command includes all standard flags', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'fl-repo', 'path' => '/home/yak/repos/fl-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'fl-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/FL-1',
    ]);

    $job = new ClarificationReplyJob($task, 'Option A');
    $job->handle($fake);

    expect($fake->lastCall())->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Choice Prompt
|--------------------------------------------------------------------------
*/

test('prompt includes the user chosen option text', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'cp-repo', 'path' => '/home/yak/repos/cp-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'cp-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/CP-1',
    ]);

    $chosenOption = 'Option 2 - Refactor the database schema';

    $job = new ClarificationReplyJob($task, $chosenOption);
    $job->handle($fake);

    expect($fake->lastCall())->not->toBeNull()
        ->and($fake->lastCall()->prompt)->toContain($chosenOption);
});

/*
|--------------------------------------------------------------------------
| Cost/Turn Accumulation
|--------------------------------------------------------------------------
*/

test('accumulates cost, turns, and duration on task', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_2',
        resultSummary: 'Done',
        costUsd: 0.75,
        numTurns: 5,
        durationMs: 30000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'acc-repo', 'path' => '/home/yak/repos/acc-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'acc-repo',
        'session_id' => 'sess_1',
        'branch_name' => 'yak/ACC-1',
        'cost_usd' => 3.00,
        'num_turns' => 20,
        'duration_ms' => 150000,
    ]);

    $job = new ClarificationReplyJob($task, 'Option A');
    $job->handle($fake);

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(3.75)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(180000);
});

/*
|--------------------------------------------------------------------------
| Claude Error
|--------------------------------------------------------------------------
*/

test('claude error response marks task as failed and checks out default branch', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Rate limited by API',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'err-repo', 'path' => '/home/yak/repos/err-repo', 'default_branch' => 'main']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'err-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/ERR-1',
    ]);

    $job = new ClarificationReplyJob($task, 'Option A');
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();

    Process::assertRan(fn ($process) => str_contains($process->command, 'git checkout main'));
});

test('malformed claude output marks task as failed', function () {
    $fake = (new FakeAgentRunner())->queueResult(new AgentRunResult(
        sessionId: '',
        resultSummary: 'Agent returned an error or malformed output',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: 'not json at all {{',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    Process::fake([
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'mf-repo', 'path' => '/home/yak/repos/mf-repo']);
    $task = YakTask::factory()->awaitingClarification()->create([
        'repo' => 'mf-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/MF-1',
    ]);

    $job = new ClarificationReplyJob($task, 'Option A');
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('ClarificationReplyJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->awaitingClarification()->make();
    $job = new ClarificationReplyJob($task, 'test reply');

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('ClarificationReplyJob has CleanupDevEnvironment middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->awaitingClarification()->create(['repo' => 'mw-repo']);

    $job = new ClarificationReplyJob($task, 'test reply');
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(EnsureDailyBudget::class)
        ->and($middleware[1])->toBeInstanceOf(CleanupDevEnvironment::class);
});
