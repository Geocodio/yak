<?php

use App\DataTransferObjects\AgentRunResult;
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
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    );
}

it('applies fresh metrics by replacing existing values', function () {
    $task = YakTask::factory()->create([
        'cost_usd' => 0,
        'num_turns' => 0,
        'duration_ms' => 0,
    ]);

    TaskMetricsAccumulator::applyFresh($task, makeResult(1.5, 10, 60000, 'sess_a'));

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(1.5)
        ->and($task->num_turns)->toBe(10)
        ->and($task->duration_ms)->toBe(60000)
        ->and($task->session_id)->toBe('sess_a');
});

it('accumulates metrics on top of existing values for retries', function () {
    $task = YakTask::factory()->create([
        'cost_usd' => 2.0,
        'num_turns' => 20,
        'duration_ms' => 120000,
        'session_id' => 'sess_a',
    ]);

    TaskMetricsAccumulator::applyAccumulated($task, makeResult(0.75, 5, 30000, 'sess_a'));

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(2.75)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(150000);
});
