<?php

use App\Models\PrReview;
use App\Models\PrReviewComment;

it('persists resolution columns on pr_review_comments', function () {
    $review = PrReview::factory()->create();
    $comment = PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'resolution_status' => 'fixed',
        'resolved_in_review_id' => $review->id,
        'resolution_reply_github_id' => 9876543,
    ]);

    $fresh = $comment->fresh();
    expect($fresh->resolution_status)->toBe('fixed')
        ->and($fresh->resolved_in_review_id)->toBe($review->id)
        ->and($fresh->resolution_reply_github_id)->toBe(9876543);
});

it('defaults resolution_status to null', function () {
    $review = PrReview::factory()->create();
    $comment = PrReviewComment::factory()->create(['pr_review_id' => $review->id]);

    expect($comment->fresh()->resolution_status)->toBeNull();
});
