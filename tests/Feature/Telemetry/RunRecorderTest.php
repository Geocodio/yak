<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\RunStats;
use App\DataTransferObjects\RunUsage;
use App\Enums\TaskRunKind;
use App\Enums\TaskRunOutcome;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\Contracts\TelemetrySink;
use App\Services\Telemetry\Telemetry;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

function successfulRunResult(): AgentRunResult
{
    $stats = new RunStats;
    $stats->cliVersion = '2.1.300';
    $stats->toolStarted('Bash');
    $stats->toolFinished('Bash', false, 1200);
    $stats->toolStarted('Read');
    $stats->toolFinished('Read', true, 30);
    $stats->toolStarted('mcp__linear__get_issue');
    $stats->toolFinished('mcp__linear__get_issue', false, 400);
    $stats->apiRetry('rate_limit');
    $stats->assistantMessage('msg_1', 'claude-opus-4-6', ['input_tokens' => 10, 'output_tokens' => 20]);

    return new AgentRunResult(
        sessionId: 'sess_run',
        resultSummary: 'Done',
        costUsd: 1.25,
        numTurns: 7,
        durationMs: 90_000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
        usage: new RunUsage(inputTokens: 1000, outputTokens: 500, cacheReadTokens: 20_000, cacheCreationTokens: 300, apiDurationMs: 60_000, modelUsage: ['claude-opus-4-6' => ['input' => 1000, 'output' => 500, 'cache_read' => 20_000, 'cache_creation' => 300, 'cost_usd' => 1.25]]),
        stats: $stats,
        permissionDenials: 2,
    );
}

function runJobWithFakes(YakTask $task, AgentRunResult|Throwable $result, ?FakeSandboxManager $sandbox = null): FakeSandboxManager
{
    Queue::fake();
    Process::fake(['*' => Process::result('')]);

    $fake = new FakeAgentRunner;
    $result instanceof Throwable ? $fake->queueException($result) : $fake->queueResult($result);
    app()->instance(AgentRunner::class, $fake);

    $sandbox ??= new FakeSandboxManager;
    app()->instance(IncusSandboxManager::class, $sandbox);

    (new RunYakJob($task))->handle($fake);

    return $sandbox;
}

test('a successful initial run records one task_runs row with stages, usage, tools and git stats', function () {
    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/widgets', 'source' => 'linear', 'dispatched_at' => now()->subSeconds(30)]);

    runJobWithFakes($task, successfulRunResult());

    $run = TaskRun::sole();

    expect($run->yak_task_id)->toBe($task->id)
        ->and($run->kind)->toBe(TaskRunKind::Initial)
        ->and($run->outcome)->toBe(TaskRunOutcome::Success)
        ->and($run->job_class)->toBe(RunYakJob::class)
        ->and($run->repo)->toBe('acme/widgets')
        ->and($run->source)->toBe('linear')
        ->and($run->mode)->toBe('fix')
        ->and($run->attempt_number)->toBe(1)
        ->and($run->queue_wait_ms)->toBeGreaterThanOrEqual(29_000)
        ->and($run->session_id)->toBe('sess_run')
        ->and($run->cli_version)->toBe('2.1.300')
        ->and((float) $run->cost_usd)->toBe(1.25)
        ->and($run->num_turns)->toBe(7)
        ->and($run->input_tokens)->toBe(1000)
        ->and($run->cache_read_tokens)->toBe(20_000)
        ->and($run->agent_api_ms)->toBe(60_000)
        ->and($run->model_usage)->toHaveKey('claude-opus-4-6')
        ->and($run->tool_calls)->toBe(3)
        ->and($run->tool_errors)->toBe(1)
        ->and($run->tool_ms)->toBe(1630)
        ->and($run->mcp_calls)->toBe(1)
        ->and($run->tool_breakdown['Bash'])->toBe(['calls' => 1, 'errors' => 0, 'ms' => 1200])
        ->and($run->api_retries)->toBe(1)
        ->and($run->api_retry_breakdown)->toBe(['rate_limit' => 1])
        ->and($run->permission_denials)->toBe(2)
        ->and($run->assistant_messages)->toBe(1)
        ->and($run->commits)->toBe(1)
        ->and($run->prompt_chars)->toBeGreaterThan(0)
        ->and($run->resumed)->toBeFalse()
        ->and($run->sandbox_create_ms)->not->toBeNull()
        ->and($run->git_prepare_ms)->not->toBeNull()
        ->and($run->agent_ms)->not->toBeNull()
        ->and($run->post_agent_ms)->not->toBeNull()
        ->and($run->teardown_ms)->not->toBeNull()
        ->and($run->total_ms)->not->toBeNull()
        ->and($run->agent_started_at)->not->toBeNull()
        ->and($run->agent_finished_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->worker_peak_mb)->toBeGreaterThan(0);
});

test('an agent error records the failure category and message', function () {
    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/widgets']);

    runJobWithFakes($task, new AgentRunResult(
        sessionId: 'sess_err',
        resultSummary: '',
        costUsd: 0.4,
        numTurns: 300,
        durationMs: 10_000,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
        errorSubtype: 'error_max_turns',
    ));

    $run = TaskRun::sole();

    expect($run->outcome)->toBe(TaskRunOutcome::Error)
        ->and($run->error_subtype)->toBe('error_max_turns')
        ->and($run->error_message)->toContain('max turns')
        ->and((float) $run->cost_usd)->toBe(0.4)
        ->and((float) $task->refresh()->cost_usd)->toBe(0.4);
});

test('a budget-cap error is categorised as budget_cap', function () {
    config(['yak.max_budget_per_task' => 5.0]);
    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/widgets']);

    runJobWithFakes($task, new AgentRunResult(
        sessionId: 'sess_budget',
        resultSummary: '',
        costUsd: 5.01,
        numTurns: 84,
        durationMs: 10_000,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
        errorSubtype: 'error_during_execution',
    ));

    expect(TaskRun::sole()->error_subtype)->toBe('budget_cap');
});

test('an exception outside the agent records an exception outcome with the class name', function () {
    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/widgets']);

    runJobWithFakes($task, new RuntimeException('incus exploded'));

    $run = TaskRun::sole();

    expect($run->outcome)->toBe(TaskRunOutcome::Exception)
        ->and($run->error_subtype)->toBe('RuntimeException')
        ->and($run->error_message)->toBe('incus exploded')
        ->and($run->agent_ms)->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

test('a clean run with no commits is recorded as no_changes', function () {
    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/widgets']);

    runJobWithFakes($task, successfulRunResult(), (new FakeSandboxManager)->setCommitCount(0));

    expect(TaskRun::sole()->outcome)->toBe(TaskRunOutcome::NoChanges)
        ->and(TaskRun::sole()->commits)->toBe(0);
});

test('a retry is recorded as its own run and adds onto the task totals', function () {
    Queue::fake();
    Process::fake(['*' => Process::result('')]);
    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->retrying()->create(['repo' => 'acme/widgets', 'cost_usd' => 2.0, 'num_turns' => 10, 'duration_ms' => 50_000]);

    $fake = (new FakeAgentRunner)->queueResult(successfulRunResult());
    app()->instance(AgentRunner::class, $fake);
    app()->instance(IncusSandboxManager::class, new FakeSandboxManager);

    (new RetryYakJob($task, 'tests failed'))->handle($fake);

    $run = TaskRun::sole();
    $task->refresh();

    expect($run->kind)->toBe(TaskRunKind::Retry)
        ->and($run->outcome)->toBe(TaskRunOutcome::Success)
        ->and((float) $task->cost_usd)->toBe(3.25)
        ->and($task->num_turns)->toBe(17)
        ->and($task->duration_ms)->toBe(140_000);
});

test('no run row is written when telemetry is disabled', function () {
    config(['yak.telemetry.enabled' => false]);
    app()->forgetInstance(Telemetry::class);
    app()->forgetInstance(TelemetrySink::class);

    Repository::factory()->create(['slug' => 'acme/widgets', 'path' => '/home/yak/repos/widgets']);
    $task = YakTask::factory()->pending()->create(['repo' => 'acme/widgets']);

    runJobWithFakes($task, successfulRunResult());

    expect(TaskRun::count())->toBe(0)
        ->and($task->refresh()->status->value)->toBe('awaiting_ci');
});
