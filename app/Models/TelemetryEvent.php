<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TelemetryEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only telemetry stream. The attribute bag is the `properties`
 * column (not `attributes`, which would collide with Eloquent's own
 * property of that name). Rows are written through
 * App\Services\Telemetry\Telemetry, never directly, so the enabled flag
 * and the sink abstraction hold everywhere.
 *
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed>|null $properties
 */
class TelemetryEvent extends Model
{
    /** @use HasFactory<TelemetryEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'properties' => 'json',
            'value' => 'float',
        ];
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    /**
     * @return BelongsTo<TaskRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class, 'task_run_id');
    }
}
