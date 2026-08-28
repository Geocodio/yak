<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\VideoMetricFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per RenderVideoJob attempt: how long the render took, what it
 * produced, or why it failed. Feeds the cost dashboard's Video panel.
 *
 * @property int $yak_task_id
 * @property int|null $artifact_id
 * @property string $status
 * @property int $render_ms
 * @property int|null $output_bytes
 * @property float|null $duration_seconds
 * @property string|null $error
 */
class VideoMetric extends Model
{
    /** @use HasFactory<VideoMetricFactory> */
    use HasFactory;

    public const string STATUS_RENDERED = 'rendered';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['duration_seconds' => 'float', 'render_ms' => 'integer', 'output_bytes' => 'integer'];
    }

    /** @return BelongsTo<YakTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }

    /**
     * @param  Builder<VideoMetric>  $query
     * @return Builder<VideoMetric>
     */
    public function scopeBetween(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }
}
