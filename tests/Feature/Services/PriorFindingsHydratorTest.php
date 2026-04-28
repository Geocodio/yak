<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\YakTask;
use App\Services\PriorFindingsHydrator;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
});

it('returns hydrated prior findings filtered by unresolved threads', function () {
    $task = YakTask::factory()->create([
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
    ]);

    $review = PrReview::factory()->create([
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
        'commit_sha_reviewed' => 'old',
    ]);
    $resolvedComment = PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 1001,
        'file_path' => 'app/A.php',
        'line_number' => 5,
        'severity' => 'must_fix',
        'category' => 'Performance',
        'body' => 'Resolved by author.',
    ]);
    $unresolvedComment = PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 2002,
        'file_path' => 'app/B.php',
        'line_number' => 10,
        'severity' => 'should_fix',
        'category' => 'Simplicity',
        'body' => 'Still open.',
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewThreads')->with(12345, 'geocodio/api', 77)->andReturn([
        ['thread_id' => 't1', 'is_resolved' => true, 'comment_database_ids' => [1001]],
        ['thread_id' => 't2', 'is_resolved' => false, 'comment_database_ids' => [2002]],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $changedFiles = ['app/B.php'];

    $hydrated = app(PriorFindingsHydrator::class)
        ->hydrate('geocodio/api', 77, 'https://github.com/geocodio/api/pull/77', $changedFiles);

    expect($hydrated)->toHaveCount(1)
        ->and($hydrated[0])->toBe([
            'comment_id' => 2002,
            'file' => 'app/B.php',
            'line' => 10,
            'severity' => 'should_fix',
            'category' => 'Simplicity',
            'body' => 'Still open.',
            'file_changed_in_this_push' => true,
        ]);
});

it('skips findings that already have a resolution_reply_github_id (idempotent on retry)', function () {
    $review = PrReview::factory()->create([
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
    ]);
    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 3003,
        'resolution_reply_github_id' => 50,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewThreads')->andReturn([
        ['thread_id' => 't', 'is_resolved' => false, 'comment_database_ids' => [3003]],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $hydrated = app(PriorFindingsHydrator::class)
        ->hydrate('geocodio/api', 77, 'https://github.com/geocodio/api/pull/77', []);

    expect($hydrated)->toBe([]);
});

it('skips sticky decisions whose reply failed (does not retry on next push)', function () {
    $review = PrReview::factory()->create([
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
    ]);

    // FIXED with no reply id (reply API failed): sticky, do not re-present.
    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 4001,
        'resolution_status' => 'fixed',
        'resolution_reply_github_id' => null,
    ]);
    // STILL_OUTSTANDING with no reply id: sticky, do not re-present.
    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 4002,
        'resolution_status' => 'still_outstanding',
        'resolution_reply_github_id' => null,
    ]);
    // UNTOUCHED on a previous push: re-present (file may now be touched).
    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 4003,
        'resolution_status' => 'untouched',
        'resolution_reply_github_id' => null,
        'file_path' => 'app/Foo.php',
        'line_number' => 1,
        'severity' => 'should_fix',
        'category' => 'Simplicity',
        'body' => 'still around',
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewThreads')->andReturn([
        ['thread_id' => 't1', 'is_resolved' => false, 'comment_database_ids' => [4001]],
        ['thread_id' => 't2', 'is_resolved' => false, 'comment_database_ids' => [4002]],
        ['thread_id' => 't3', 'is_resolved' => false, 'comment_database_ids' => [4003]],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $hydrated = app(PriorFindingsHydrator::class)
        ->hydrate('geocodio/api', 77, 'https://github.com/geocodio/api/pull/77', ['app/Foo.php']);

    $ids = array_column($hydrated, 'comment_id');
    expect($ids)->toBe([4003]);
});

it('returns empty array and logs warning when GraphQL fails', function () {
    PrReviewComment::factory()->create(['github_comment_id' => 1001]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewThreads')->andThrow(new RuntimeException('graphql exploded'));
    app()->instance(GitHubAppService::class, $github);

    $hydrated = app(PriorFindingsHydrator::class)
        ->hydrate('geocodio/api', 77, 'https://github.com/geocodio/api/pull/77', []);

    expect($hydrated)->toBe([]);
});

it('caps at max_findings_per_review and drops oldest first', function () {
    config()->set('yak.pr_review.max_findings_per_review', 2);

    $review = PrReview::factory()->create([
        'pr_url' => 'https://github.com/geocodio/api/pull/77',
    ]);

    foreach ([1001, 1002, 1003] as $i => $id) {
        PrReviewComment::factory()->create([
            'pr_review_id' => $review->id,
            'github_comment_id' => $id,
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewThreads')->andReturn([
        ['thread_id' => 't1', 'is_resolved' => false, 'comment_database_ids' => [1001]],
        ['thread_id' => 't2', 'is_resolved' => false, 'comment_database_ids' => [1002]],
        ['thread_id' => 't3', 'is_resolved' => false, 'comment_database_ids' => [1003]],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $hydrated = app(PriorFindingsHydrator::class)
        ->hydrate('geocodio/api', 77, 'https://github.com/geocodio/api/pull/77', []);

    $ids = array_column($hydrated, 'comment_id');
    expect($ids)->toBe([1002, 1003]);
});
