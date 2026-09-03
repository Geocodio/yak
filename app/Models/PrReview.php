<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PrReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cast-backed attributes are declared here because Larastan resolves casts
 * from the `$casts` property only -- it does not read the `casts()` method
 * form this model uses, so without these it infers the raw column types.
 *
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $dismissed_at
 * @property CarbonImmutable|null $pr_closed_at
 * @property CarbonImmutable|null $pr_merged_at
 */
class PrReview extends Model
{
    /** @use HasFactory<PrReviewFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'pr_closed_at' => 'datetime',
            'pr_merged_at' => 'datetime',
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
     * @return HasMany<PrReviewComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PrReviewComment::class);
    }
}
