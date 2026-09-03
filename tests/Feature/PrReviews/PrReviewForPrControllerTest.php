<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('yak.channels.github.installation_id', 12345);
});

test('show lists reviews for a PR in reverse chronological order', function () {
    $older = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 50,
        'submitted_at' => now()->subDay(),
    ]);
    $newer = PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => 50,
        'submitted_at' => now(),
    ]);
    PrReviewComment::factory()->create(['pr_review_id' => $newer->id, 'severity' => 'must_fix']);

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/ForPr')
            ->where('pr.repoSlug', 'geocodio/api')
            ->where('pr.number', 50)
            ->has('reviews', 2, fn (Assert $row) => $row
                ->where('id', $newer->id)
                ->has('findings')
                ->has('createdAgo')
                ->has('reviewer')
                ->etc())
            ->has('reviews.1', fn (Assert $row) => $row
                ->where('id', $older->id)
                ->etc()));
});

test('show renders an empty state when no reviews exist for the pr', function () {
    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/ForPr')
            ->has('reviews', 0));
});

test('rerun enqueues a review task', function () {
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn([
        'number' => 50, 'html_url' => 'https://github.com/geocodio/api/pull/50',
        'title' => 'Fix the thing', 'body' => '', 'draft' => false, 'user' => ['login' => 'm'],
        'head' => ['ref' => 'h', 'sha' => 's'], 'base' => ['ref' => 'main', 'sha' => 'b'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    $this->post(route('pr-reviews.for-pr.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertRedirect(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertSessionHas('success');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

test('rerun reports an error when the PR cannot be fetched from github', function () {
    Repository::factory()->create(['slug' => 'geocodio/api']);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn([]);
    app()->instance(GitHubAppService::class, $github);

    $this->post(route('pr-reviews.for-pr.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertRedirect(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertSessionHas('error');

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(0);
});
