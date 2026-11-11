<?php

use App\DataTransferObjects\AgentRunResult;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\TaskMetricsAccumulator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeResult(float $cost, int $turns, int $duration, string $session = 'sess_1'): AgentRunResult
{
    return new AgentRunResult(
        sessionId: $session,
        resultSummary: 'ok',
        costUsd: $cost,
        numTurns: $turns,
        durationMs: $duration,
        isError: false,
        rawOutput: '{}',
    );
}

it('adds a first run onto an empty task and records the session', function () {
    $task = YakTask::factory()->create([
        'cost_usd' => 0,
        'num_turns' => 0,
        'duration_ms' => 0,
    ]);

    TaskMetricsAccumulator::record($task, makeResult(1.5, 10, 60000, 'sess_a'));

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(1.5)
        ->and($task->num_turns)->toBe(10)
        ->and($task->duration_ms)->toBe(60000)
        ->and($task->session_id)->toBe('sess_a');
});

it('accumulates metrics on top of existing values so a retry keeps the earlier cost', function () {
    $task = YakTask::factory()->create([
        'cost_usd' => 2.0,
        'num_turns' => 20,
        'duration_ms' => 120000,
        'session_id' => 'sess_a',
    ]);

    TaskMetricsAccumulator::record($task, makeResult(0.75, 5, 30000, 'sess_b'));

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(2.75)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(150000)
        ->and($task->session_id)->toBe('sess_b');
});

it('keeps the previous session id when a failed run reports none', function () {
    $task = YakTask::factory()->create(['session_id' => 'sess_a']);

    TaskMetricsAccumulator::record($task, makeResult(0.1, 1, 1000, ''));

    expect($task->refresh()->session_id)->toBe('sess_a');
});

it('counts only the cost above what earlier runs of a resumed session reported', function () {
    $task = YakTask::factory()->create(['cost_usd' => 1.0, 'session_id' => 'sess_a']);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'session_id' => 'sess_a', 'cost_usd' => 1.0]);

    TaskMetricsAccumulator::record($task, makeResult(1.4, 3, 1000, 'sess_a'));

    expect((float) $task->refresh()->cost_usd)->toBe(1.4);
});
