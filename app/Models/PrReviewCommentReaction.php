<?php

namespace App\Models;

use Database\Factories\PrReviewCommentReactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $total Only present on results of the aggregate query in
 *     PrReviewFeedbackController::reviewerStats() (selectRaw COUNT(*) as total).
 * @property-read int $up Only present on results of that same aggregate query
 *     (selectRaw SUM(...) as up).
 * @property-read int $down Only present on results of that same aggregate
 *     query (selectRaw SUM(...) as down).
 */
class PrReviewCommentReaction extends Model
{
    /** @use HasFactory<PrReviewCommentReactionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reacted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PrReviewComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(PrReviewComment::class, 'pr_review_comment_id');
    }
}
