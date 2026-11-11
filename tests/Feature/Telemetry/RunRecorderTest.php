<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\RunStats;
use App\DataTransferObjects\RunUsage;
use App\Enums\TaskRunKind;
use App\Enums\TaskRunOutcome;
use App\Jobs\RetryYakJob;
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
        rawOutput: '{}',
        usage: new RunUsage(inputTokens: 1000, outputTokens: 500, cacheReadTokens: 20_000, cacheCreationTokens: 300, apiDurationMs: 60_000, modelUsage: ['claude-opus-4-6' => ['input' => 1000, 'output' => 500, 'cache_read' => 20_000, 'cache_creation' => 300, 'cost_usd' => 1.25]]),
        stats: $stats,
        permissionDenials: 2,
    );
}

require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

function finishTurnWith(array|false $event = [], ?FakeSandboxManager $shell = null, array $taskAttributes = []): array
{
    Queue::fake();
    fakeAfterTurnCollaborators(shell: $shell);

    [$task, $run] = endedTurn($event, $taskAttributes, ['slug' => 'acme/widgets']);
    $finishedAt = $run->fresh()->finished_at;

    runFinisher($run);

    return [$task->refresh(), $run->refresh(), $finishedAt];
}

test('a successful turn adds its outcome and git stats to the turn row and keeps the driver timing', function () {
    [$task, $run, $finishedAt] = finishTurnWith(resultEvent('Done'));

    expect(TaskRun::count())->toBe(1)
        ->and($run->yak_task_id)->toBe($task->id)
        ->and($run->kind)->toBe(TaskRunKind::Initial)
        ->and($run->outcome)->toBe(TaskRunOutcome::Success)
        ->and($run->commits)->toBe(1)
        ->and($run->finished_at->equalTo($finishedAt))->toBeTrue()
        ->and($run->worker_peak_mb)->toBeGreaterThan(0);
});

test('an agent error records the failure category and message', function () {
    [$task, $run] = finishTurnWith(resultEvent('', ['is_error' => true, 'subtype' => 'error_max_turns']));

    expect($run->outcome)->toBe(TaskRunOutcome::Error)
        ->and($run->error_subtype)->toBe('error_max_turns')
        ->and($run->error_message)->toContain('max turns')
        ->and((float) $task->cost_usd)->toBe(0.25);
});

test('a turn that ended without a result records an error outcome', function () {
    [, $run] = finishTurnWith(false);

    expect($run->outcome)->toBe(TaskRunOutcome::Error)
        ->and($run->error_message)->toContain('without a result');
});

test('an exception after the agent records an exception outcome with the class name', function () {
    [, $run] = finishTurnWith(shell: (new FakeSandboxManager)->failCommand('git push', 'remote rejected'));

    expect($run->outcome)->toBe(TaskRunOutcome::Exception)
        ->and($run->error_subtype)->toBe('RuntimeException')
        ->and($run->error_message)->toContain('remote rejected');
});

test('a clean turn with no commits is recorded as no_changes', function () {
    [, $run] = finishTurnWith(shell: (new FakeSandboxManager)->setCommitCount(0));

    expect($run->outcome)->toBe(TaskRunOutcome::NoChanges)
        ->and($run->commits)->toBe(0);
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

test('the turn row keeps only the driver columns when telemetry is disabled', function () {
    config(['yak.telemetry.enabled' => false]);
    app()->forgetInstance(Telemetry::class);
    app()->forgetInstance(TelemetrySink::class);

    [$task, $run] = finishTurnWith(taskAttributes: ['repo' => 'acme/widgets']);

    expect(TaskRun::count())->toBe(1)
        ->and($run->outcome)->toBe(TaskRunOutcome::Success)
        ->and($task->status->value)->toBe('awaiting_ci');
});
