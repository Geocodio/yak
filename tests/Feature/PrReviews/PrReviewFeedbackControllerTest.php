<?php

use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('index renders the feedback table with comments', function () {
    $review = PrReview::factory()->create(['repo' => 'geocodio/api', 'pr_number' => 50]);
    PrReviewComment::factory()->count(3)->create(['pr_review_id' => $review->id]);

    $this->get(route('pr-reviews'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/Index')
            ->has('comments.data', 3)
            ->has('stats')
            ->has('reviewerStats')
            ->has('filters'));
});

test('index filters by severity', function () {
    PrReviewComment::factory()->create(['severity' => 'must_fix']);
    PrReviewComment::factory()->create(['severity' => 'consider']);

    $this->get(route('pr-reviews', ['severity' => 'must_fix']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/Index')
            ->has('comments.data', 1, fn (Assert $row) => $row
                ->where('severity', 'must_fix')
                ->etc())
            ->where('filters.severity', 'must_fix'));
});

test('index filters by category', function () {
    PrReviewComment::factory()->create(['category' => 'Simplicity']);
    PrReviewComment::factory()->create(['category' => 'Performance']);

    $this->get(route('pr-reviews', ['category' => 'Simplicity']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('comments.data', 1, fn (Assert $row) => $row
                ->where('category', 'Simplicity')
                ->etc()));
});

test('index filters by repo', function () {
    PrReview::factory()->create(['repo' => 'geocodio/api'])->comments()->save(PrReviewComment::factory()->make());
    PrReview::factory()->create(['repo' => 'geocodio/dashboard'])->comments()->save(PrReviewComment::factory()->make());

    $this->get(route('pr-reviews', ['repo' => 'geocodio/api']))
        ->assertInertia(fn (Assert $page) => $page->has('comments.data', 1));
});

test('index filters by scope', function () {
    $full = PrReview::factory()->create(['review_scope' => 'full']);
    $incremental = PrReview::factory()->incremental()->create();
    PrReviewComment::factory()->create(['pr_review_id' => $full->id]);
    PrReviewComment::factory()->create(['pr_review_id' => $incremental->id]);

    $this->get(route('pr-reviews', ['scope' => 'incremental']))
        ->assertInertia(fn (Assert $page) => $page->has('comments.data', 1));
});

test('index filters by reviewer reaction login', function () {
    $withReaction = PrReviewComment::factory()->create();
    PrReviewCommentReaction::factory()->create([
        'pr_review_comment_id' => $withReaction->id,
        'github_user_login' => 'octocat',
    ]);
    PrReviewComment::factory()->create();

    $this->get(route('pr-reviews', ['reviewer' => 'octocat']))
        ->assertInertia(fn (Assert $page) => $page->has('comments.data', 1));
});

test('index filters to reactions only', function () {
    $withReaction = PrReviewComment::factory()->create(['thumbs_up' => 1]);
    PrReviewComment::factory()->create(['thumbs_up' => 0, 'thumbs_down' => 0]);

    $this->get(route('pr-reviews', ['reactions' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('comments.data', 1, fn (Assert $row) => $row
                ->where('id', $withReaction->id)
                ->etc()));
});

test('index sorts comments', function () {
    PrReviewComment::factory()->create(['severity' => 'consider', 'file_path' => 'b.php']);
    PrReviewComment::factory()->create(['severity' => 'must_fix', 'file_path' => 'a.php']);

    $this->get(route('pr-reviews', ['sort' => 'file_path', 'dir' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('comments.data', 2, fn (Assert $row) => $row
                ->where('filePath', 'a.php')
                ->etc())
            ->where('filters.sort', 'file_path')
            ->where('filters.dir', 'asc'));
});

test('index ignores an unknown sort column', function () {
    PrReviewComment::factory()->create();

    $this->get(route('pr-reviews', ['sort' => 'not_a_real_column']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'submitted_at'));
});

test('index reports stats', function () {
    $review = PrReview::factory()->create();
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'is_suggestion' => true, 'thumbs_up' => 2, 'thumbs_down' => 0]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'is_suggestion' => false, 'thumbs_up' => 0, 'thumbs_down' => 3, 'category' => 'Simplicity']);

    $this->get(route('pr-reviews'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.reviews', 1)
            ->where('stats.suggestions', 1)
            ->where('stats.mostDownvotedCategory', 'Simplicity'));
});

test('index reports reviewer stats grouped by reaction login', function () {
    $comment = PrReviewComment::factory()->create();
    PrReviewCommentReaction::factory()->create(['pr_review_comment_id' => $comment->id, 'github_user_login' => 'octocat', 'content' => '+1']);
    PrReviewCommentReaction::factory()->create(['pr_review_comment_id' => $comment->id, 'github_user_login' => 'octocat', 'content' => '-1']);

    $this->get(route('pr-reviews'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reviewerStats', 1, fn (Assert $row) => $row
                ->where('login', 'octocat')
                ->where('total', 2)
                ->where('up', 1)
                ->where('down', 1)
                ->etc()));
});

test('index shows an empty state with no reviews', function () {
    $this->get(route('pr-reviews'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.reviews', 0)
            ->has('comments.data', 0));
});
