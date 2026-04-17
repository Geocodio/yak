<?php

namespace App\Models;

use Database\Factories\PrReviewCommentReactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
