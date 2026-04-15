<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\RetryYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/*
|--------------------------------------------------------------------------
| Successful Retry
|--------------------------------------------------------------------------
*/

test('successful retry transitions task to awaiting_ci and force pushes branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_retry_new',
        resultSummary: 'Fixed the CI failures',
        costUsd: 1.50,
        numTurns: 10,
        durationMs: 90000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'test-repo',
        'session_id' => 'sess_original',
        'branch_name' => 'yak/ISSUE-100',
        'cost_usd' => 2.00,
        'num_turns' => 15,
        'duration_ms' => 120000,
    ]);

    $job = new RetryYakJob($task, 'Tests failed: 2 errors');
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_retry_new')
        ->and($task->result_summary)->toBe('Fixed the CI failures')
        ->and((float) $task->cost_usd)->toBe(3.50)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(210000);

    // Sandbox was created and destroyed
    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

test('successful retry accumulates cost and turns on task', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
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

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'acc-repo', 'path' => '/home/yak/repos/acc-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acc-repo',
        'session_id' => 'sess_1',
        'branch_name' => 'yak/ACC-1',
        'cost_usd' => 3.00,
        'num_turns' => 20,
        'duration_ms' => 150000,
    ]);

    $job = new RetryYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(3.75)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(180000);
});

/*
|--------------------------------------------------------------------------
| Claude --resume Flag
|--------------------------------------------------------------------------
*/

test('invokes claude with --resume flag and session_id', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
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

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'res-repo', 'path' => '/home/yak/repos/res-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'res-repo',
        'session_id' => 'sess_original_123',
        'branch_name' => 'yak/RES-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle($fake);

    expect($fake->lastCall()->resumeSessionId)->toBe('sess_original_123');
});

/*
|--------------------------------------------------------------------------
| Retry Prompt
|--------------------------------------------------------------------------
*/

test('retry prompt includes CI failure output', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
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

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'pr-repo', 'path' => '/home/yak/repos/pr-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'pr-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/PR-1',
    ]);

    $failureOutput = 'FAIL tests/Feature/AuthTest.php - Expected 200 got 500';

    $job = new RetryYakJob($task, $failureOutput);
    $job->handle($fake);

    expect($fake->lastCall())->not->toBeNull()
        ->and($fake->lastCall()->prompt)->toContain('CI')
        ->and($fake->lastCall()->prompt)->toContain($failureOutput);
});

/*
|--------------------------------------------------------------------------
| Claude Error
|--------------------------------------------------------------------------
*/

test('claude error response marks task as failed', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
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

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'err-repo', 'path' => '/home/yak/repos/err-repo', 'default_branch' => 'main']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'err-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/ERR-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('RetryYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->retrying()->make();
    $job = new RetryYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('RetryYakJob has EnsureDailyBudget middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->retrying()->create(['repo' => 'mw-repo']);

    $job = new RetryYakJob($task);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(EnsureDailyBudget::class);
});

/*
|--------------------------------------------------------------------------
| Sandbox Lifecycle
|--------------------------------------------------------------------------
*/

test('sandbox is destroyed even when retry fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: 'Error',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);

    Repository::factory()->create(['slug' => 'sb-repo', 'path' => '/home/yak/repos/sb-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'sb-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/SB-1',
    ]);

    (new RetryYakJob($task))->handle($fake);

    expect($fakeSandbox->destroyedContainers)->toHaveCount(1);
});
