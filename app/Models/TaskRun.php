<?php

namespace App\Models;

use App\Enums\TaskRunKind;
use App\Enums\TaskRunOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\TaskRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per agent invocation: the timing of every stage, what the CLI
 * reported (cost, turns, tokens), what the agent did (tool calls, commits)
 * and how it ended. Written by RunRecorder; the task row keeps lifetime
 * totals, this keeps the per-run breakdown that those totals lose.
 *
 * @property TaskRunKind $kind
 * @property TaskRunOutcome|null $outcome
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $agent_started_at
 * @property CarbonImmutable|null $agent_finished_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, int>|null $stages
 * @property array<string, array<string, int|float>>|null $model_usage
 * @property array<string, array{calls: int, errors: int, ms: int}>|null $tool_breakdown
 * @property array<string, int>|null $api_retry_breakdown
 */
class TaskRun extends Model
{
    /** @use HasFactory<TaskRunFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TaskRunKind::class,
            'outcome' => TaskRunOutcome::class,
            'resumed' => 'boolean',
            'stale_session_retry' => 'boolean',
            'synthesized_result' => 'boolean',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'agent_started_at' => 'datetime',
            'agent_finished_at' => 'datetime',
            'finished_at' => 'datetime',
            'stages' => 'json',
            'model_usage' => 'json',
            'tool_breakdown' => 'json',
            'api_retry_breakdown' => 'json',
            'cost_usd' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }
}
