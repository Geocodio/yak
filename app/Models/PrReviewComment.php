<?php

namespace App\Models;

use Database\Factories\PrReviewCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrReviewComment extends Model
{
    /** @use HasFactory<PrReviewCommentFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_suggestion' => 'boolean',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PrReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(PrReview::class, 'pr_review_id');
    }

    /**
     * @return HasMany<PrReviewCommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(PrReviewCommentReaction::class);
    }
}
