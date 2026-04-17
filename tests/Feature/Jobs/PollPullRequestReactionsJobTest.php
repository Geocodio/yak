<?php

use App\Jobs\PollPullRequestReactionsJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use App\Services\GitHubAppService;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.pr_review.reaction_poll_window_days', 30);
});

it('creates new reactions and updates denormalized counts', function () {
    $review = PrReview::factory()->create(['repo' => 'geocodio/api']);
    $comment = PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 555,
        'thumbs_up' => 0,
        'thumbs_down' => 0,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listCommentReactions')
        ->with(12345, 'geocodio/api', 555)
        ->andReturn([
            ['id' => 1, 'user' => ['login' => 'maria', 'id' => 1], 'content' => '+1', 'created_at' => '2026-04-17T00:00:00Z'],
            ['id' => 2, 'user' => ['login' => 'bob', 'id' => 2], 'content' => '+1', 'created_at' => '2026-04-17T00:00:00Z'],
            ['id' => 3, 'user' => ['login' => 'eve', 'id' => 3], 'content' => '-1', 'created_at' => '2026-04-17T00:00:00Z'],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new PollPullRequestReactionsJob)->handle($github);

    $comment->refresh();
    expect($comment->thumbs_up)->toBe(2)
        ->and($comment->thumbs_down)->toBe(1)
        ->and($comment->last_polled_at)->not->toBeNull()
        ->and(PrReviewCommentReaction::count())->toBe(3);
});

it('deletes reactions that no longer exist on GitHub', function () {
    $review = PrReview::factory()->create(['repo' => 'geocodio/api']);
    $comment = PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 777,
    ]);

    // Existing reaction we'll delete
    PrReviewCommentReaction::create([
        'pr_review_comment_id' => $comment->id,
        'github_reaction_id' => 99,
        'github_user_login' => 'stale',
        'github_user_id' => 100,
        'content' => '+1',
        'reacted_at' => now(),
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listCommentReactions')->andReturn([]); // GitHub says no reactions
    app()->instance(GitHubAppService::class, $github);

    (new PollPullRequestReactionsJob)->handle($github);

    expect(PrReviewCommentReaction::count())->toBe(0)
        ->and($comment->fresh()->thumbs_up)->toBe(0);
});

it('skips non-thumb reactions', function () {
    $review = PrReview::factory()->create(['repo' => 'geocodio/api']);
    $comment = PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 888,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listCommentReactions')->andReturn([
        ['id' => 1, 'user' => ['login' => 'm', 'id' => 1], 'content' => 'laugh', 'created_at' => '2026-04-17T00:00:00Z'],
        ['id' => 2, 'user' => ['login' => 'b', 'id' => 2], 'content' => '+1', 'created_at' => '2026-04-17T00:00:00Z'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    (new PollPullRequestReactionsJob)->handle($github);

    expect(PrReviewCommentReaction::count())->toBe(1);
});

it('excludes comments from PRs closed outside the window', function () {
    $oldReview = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_closed_at' => now()->subDays(45),
    ]);
    PrReviewComment::factory()->create(['pr_review_id' => $oldReview->id, 'github_comment_id' => 1000]);

    $github = mock(GitHubAppService::class);
    $github->shouldNotReceive('listCommentReactions');
    app()->instance(GitHubAppService::class, $github);

    (new PollPullRequestReactionsJob)->handle($github);
});
