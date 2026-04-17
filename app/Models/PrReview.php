<?php

namespace App\Models;

use Database\Factories\PrReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
