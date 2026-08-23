<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);

    Repository::factory()->create([
        'slug' => 'geocodio/api',
        'is_active' => true,
    ]);
});

it('backfills comments for reviews that have none stored', function () {
    $review = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 42,
        'github_review_id' => 7777,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewComments')
        ->once()
        ->andReturn([
            [
                'id' => 111,
                'path' => 'app/Foo.php',
                'line' => 12,
                'body' => "**[Performance · MUST_FIX]**\n\nNull check missing.",
            ],
            [
                'id' => 112,
                'path' => 'app/Bar.php',
                'line' => 3,
                'body' => "**[Style · NITPICK]**\n\nRename this.\n\n```suggestion\n\$betterName = 1;\n```",
            ],
        ]);
    app()->instance(GitHubAppService::class, $github);

    $this->artisan('yak:backfill-pr-review-comments')->assertSuccessful();

    expect(PrReviewComment::count())->toBe(2);

    $first = PrReviewComment::where('github_comment_id', 111)->first();
    expect($first->pr_review_id)->toBe($review->id)
        ->and($first->file_path)->toBe('app/Foo.php')
        ->and($first->line_number)->toBe(12)
        ->and($first->category)->toBe('Performance')
        ->and($first->severity)->toBe('must_fix')
        ->and($first->is_suggestion)->toBeFalse();

    $second = PrReviewComment::where('github_comment_id', 112)->first();
    expect($second->category)->toBe('Style')
        ->and($second->severity)->toBe('consider')
        ->and($second->is_suggestion)->toBeTrue();
});

it('skips reviews that already have stored comments', function () {
    $review = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'github_review_id' => 7777,
    ]);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id]);

    $github = mock(GitHubAppService::class);
    $github->shouldNotReceive('listReviewComments');
    app()->instance(GitHubAppService::class, $github);

    $this->artisan('yak:backfill-pr-review-comments')->assertSuccessful();

    expect(PrReviewComment::count())->toBe(1);
});

it('skips reviews without a github review id', function () {
    PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'github_review_id' => null,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldNotReceive('listReviewComments');
    app()->instance(GitHubAppService::class, $github);

    $this->artisan('yak:backfill-pr-review-comments')->assertSuccessful();

    expect(PrReviewComment::count())->toBe(0);
});

it('does not write anything in dry-run mode', function () {
    PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'github_review_id' => 7777,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewComments')->once()->andReturn([
        ['id' => 111, 'path' => 'app/Foo.php', 'line' => 12, 'body' => "**[Performance · MUST_FIX]**\n\nBody."],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $this->artisan('yak:backfill-pr-review-comments --dry-run')->assertSuccessful();

    expect(PrReviewComment::count())->toBe(0);
});

it('falls back to defaults when a comment body has no finding header', function () {
    PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'github_review_id' => 7777,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewComments')->once()->andReturn([
        ['id' => 111, 'path' => 'app/Foo.php', 'line' => 12, 'body' => 'Plain comment, no header.'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $this->artisan('yak:backfill-pr-review-comments')->assertSuccessful();

    $comment = PrReviewComment::first();
    expect($comment->category)->toBe('Uncategorized')
        ->and($comment->severity)->toBe('consider');
});

it('continues past reviews whose comments cannot be fetched', function () {
    PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 41,
        'github_review_id' => 6666,
    ]);
    PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 42,
        'github_review_id' => 7777,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listReviewComments')
        ->twice()
        ->andReturnUsing(function ($_i, $_r, $_p, int $reviewId) {
            if ($reviewId === 6666) {
                throw new RuntimeException('GitHub rejected the request (status 404)');
            }

            return [
                ['id' => 111, 'path' => 'app/Foo.php', 'line' => 12, 'body' => "**[Performance · MUST_FIX]**\n\nBody."],
            ];
        });
    app()->instance(GitHubAppService::class, $github);

    $this->artisan('yak:backfill-pr-review-comments')->assertSuccessful();

    expect(PrReviewComment::count())->toBe(1);
});
