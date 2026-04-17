<?php

use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;

it('creates a reaction tied to a comment', function () {
    $comment = PrReviewComment::factory()->create();

    $reaction = PrReviewCommentReaction::create([
        'pr_review_comment_id' => $comment->id,
        'github_reaction_id' => 123,
        'github_user_login' => 'maria',
        'github_user_id' => 5,
        'content' => '+1',
        'reacted_at' => now(),
    ]);

    expect($reaction->comment->id)->toBe($comment->id);
});
