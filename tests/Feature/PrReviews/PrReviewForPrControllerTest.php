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

test('show lists the runs of a PR in reverse chronological order', function () {
    $older = seedReviewRun(['status' => 'success', 'created_at' => now()->subDay()]);
    $newer = seedReviewRun(['status' => 'success', 'created_at' => now()]);
    PrReview::factory()->create(['yak_task_id' => $older->id, 'repo' => 'geocodio/api', 'pr_number' => 50]);
    $newerReview = PrReview::factory()->create(['yak_task_id' => $newer->id, 'repo' => 'geocodio/api', 'pr_number' => 50]);
    PrReviewComment::factory()->create(['pr_review_id' => $newerReview->id, 'severity' => 'must_fix']);

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/ForPr')
            ->where('pr.repoSlug', 'geocodio/api')
            ->where('pr.number', 50)
            ->has('runs', 2)
            ->where('runs.0.id', $newer->public_id)
            ->where('runs.0.counts.mustFix', 1)
            ->where('runs.1.id', $older->public_id));
});

test('show renders an empty state when no reviews exist for the pr', function () {
    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/ForPr')
            ->has('runs', 0));
});

test('show exposes the posted event separately from the model verdict and shadow recommendation', function () {
    $task = seedReviewRun(['status' => 'success']);
    PrReview::factory()->create([
        'yak_task_id' => $task->id,
        'repo' => 'geocodio/api', 'pr_number' => 50, 'verdict' => 'Approve',
        'risk_assessment' => [
            'event' => 'COMMENT', 'candidate' => 'APPROVE', 'mode' => 'shadow',
            'risk_score' => 25, 'model_confidence' => 90,
        ],
    ]);

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('runs.0.findings.verdict', 'Approve')
            ->where('runs.0.findings.riskAssessment.event', 'COMMENT')
            ->where('runs.0.findings.riskAssessment.candidate', 'APPROVE')
            ->where('runs.0.findings.riskAssessment.risk_score', 25));
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
