<?php

use App\Models\PrReview;
use App\Models\PrReviewComment;

it('creates a comment row tied to a review', function () {
    $review = PrReview::factory()->create();

    $comment = PrReviewComment::create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 99,
        'file_path' => 'app/Services/Foo.php',
        'line_number' => 87,
        'body' => 'Consider extracting this.',
        'category' => 'Simplicity',
        'severity' => 'should_fix',
        'is_suggestion' => true,
        'thumbs_up' => 0,
        'thumbs_down' => 0,
    ]);

    expect($comment->review->id)->toBe($review->id)
        ->and($comment->is_suggestion)->toBeTrue();
});
