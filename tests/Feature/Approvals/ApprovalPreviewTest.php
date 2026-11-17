<?php

use App\Ai\Agents\ConcernClassifier;
use App\Channels\GitHub\AppService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CheckOpenPullRequestApprovalsJob;
use App\Jobs\CheckPullRequestApprovalJob;
use App\Models\ApprovalPreview;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\User;
use App\Services\ReviewApprovalPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;

function previewPullRequest(int $number = 7, string $sha = 'sha7', array $overrides = []): array
{
    return array_replace_recursive([
        'number' => $number, 'title' => "PR {$number}", 'html_url' => "https://github.com/acme/api/pull/{$number}",
        'state' => 'open', 'draft' => false, 'auto_merge' => null, 'changed_files' => 1, 'user' => ['login' => 'alice'],
        'head' => ['sha' => $sha, 'repo' => ['full_name' => 'acme/api']], 'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/api']],
    ], $overrides);
}

function previewFiles(string ...$paths): array
{
    return array_map(fn (string $path): array => ['filename' => $path, 'status' => 'modified', 'patch' => '+x'], $paths ?: ['app/Thing.php']);
}

function previewReview(array $attributes = [], array $assessment = []): PrReview
{
    return PrReview::factory()->create($attributes + [
        'repo' => 'acme/api', 'pr_number' => 7, 'pr_url' => 'https://github.com/acme/api/pull/7',
        'commit_sha_reviewed' => 'sha7', 'verdict' => 'Approve', 'review_scope' => 'full',
        'risk_assessment' => $assessment,
    ]);
}

function previewClassification(string $kind = 'presentation_or_internal'): array
{
    return ['touched_concerns' => [], 'change_kind' => $kind, 'is_uncertain' => false, 'failure' => null];
}

beforeEach(function () {
    Http::fake();
    config(['ai.providers.anthropic.key' => 'sk-ant-test']);
    $this->repo = Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api', 'pr_review_enabled' => true, 'is_active' => true]);
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    $this->github = mock(AppService::class);
    $this->github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $this->github->shouldReceive('hasRequiredStatusChecks')->andReturn(true)->byDefault();
    $this->github->shouldReceive('dismissesStaleReviews')->andReturn(true)->byDefault();
    app()->instance(AppService::class, $this->github);
});

afterEach(function () {
    Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PUT', 'PATCH'], true) && str_contains($request->url(), 'api.github.com'));
});

it('queues one check per open pull request and creates pending rows', function () {
    Queue::fake();
    $this->github->shouldReceive('listOpenPullRequests')->andReturn([previewPullRequest(7), previewPullRequest(8, 'sha8')]);

    (new CheckOpenPullRequestApprovalsJob($this->repo->id))->handle($this->github);

    expect(ApprovalPreview::where('repository_id', $this->repo->id)->pluck('status', 'pr_number')->all())->toBe([7 => 'pending', 8 => 'pending']);
    Queue::assertPushed(CheckPullRequestApprovalJob::class, 2);
});

it('does not recheck a pull request at an unchanged head and concerns version', function () {
    Queue::fake();
    $version = $this->repo->settings()->concernsVersion();
    ApprovalPreview::factory()->done()->create(['repository_id' => $this->repo->id, 'pr_number' => 7, 'head_sha' => 'sha7', 'concerns_version' => $version]);
    ApprovalPreview::factory()->done()->create(['repository_id' => $this->repo->id, 'pr_number' => 8, 'head_sha' => 'old', 'concerns_version' => $version]);
    ApprovalPreview::factory()->done()->create(['repository_id' => $this->repo->id, 'pr_number' => 9, 'head_sha' => 'sha9', 'concerns_version' => 'older']);
    $this->github->shouldReceive('listOpenPullRequests')->andReturn([previewPullRequest(7), previewPullRequest(8, 'sha8'), previewPullRequest(9, 'sha9')]);

    (new CheckOpenPullRequestApprovalsJob($this->repo->id))->handle($this->github);

    expect(ApprovalPreview::where('pr_number', 7)->value('status'))->toBe('done')
        ->and(ApprovalPreview::where('pr_number', 8)->value('status'))->toBe('pending')
        ->and(ApprovalPreview::where('pr_number', 9)->value('status'))->toBe('pending');
    Queue::assertPushed(CheckPullRequestApprovalJob::class, 2);
});

it('removes the row of a pull request that is no longer open', function () {
    Queue::fake();
    ApprovalPreview::factory()->done()->create(['repository_id' => $this->repo->id, 'pr_number' => 5]);
    $this->github->shouldReceive('listOpenPullRequests')->andReturn([]);

    (new CheckOpenPullRequestApprovalsJob($this->repo->id))->handle($this->github);

    expect(ApprovalPreview::count())->toBe(0);
});

it('keeps the rows and marks them failed when GitHub cannot list pull requests', function () {
    Queue::fake();
    ApprovalPreview::factory()->done()->create(['repository_id' => $this->repo->id, 'pr_number' => 5]);
    $this->github->shouldReceive('listOpenPullRequests')->andThrow(new RuntimeException('500'));

    (new CheckOpenPullRequestApprovalsJob($this->repo->id))->handle($this->github);

    expect(ApprovalPreview::first()->status)->toBe('failed');
});

it('waits without a review at the head commit and never calls the classifier', function () {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification()]);
    previewReview(['commit_sha_reviewed' => 'other']);

    $latest = PrReview::where('commit_sha_reviewed', 'sha7')->first();
    $outcome = app(ReviewApprovalPolicy::class)->preview($this->repo, previewPullRequest(), previewFiles(), $latest, null);

    expect($outcome['waiting'])->toBeTrue()->and($outcome['holds'])->toBe([])->and($outcome['classification'])->toBeNull();
    ConcernClassifier::assertNeverPrompted();
});

it('writes no review rows', function () {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification(), previewClassification()]);
    $review = previewReview();
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'resolution_status' => 'fixed']);
    $counts = [PrReview::count(), PrReviewComment::count()];

    app(ReviewApprovalPolicy::class)->preview($this->repo, previewPullRequest(), previewFiles(), $review->load('comments'), null);

    expect([PrReview::count(), PrReviewComment::count()])->toBe($counts);
});

it('holds a migration file with the floor reason', function () {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification()]);

    $outcome = app(ReviewApprovalPolicy::class)->preview($this->repo, previewPullRequest(), previewFiles('database/migrations/2026_01_01_000000_x.php'), previewReview(), null);

    expect($outcome['holds'][0])->toBe('Always needs a human: database/migrations/2026_01_01_000000_x.php');
    ConcernClassifier::assertNeverPrompted();
});

it('holds draft, Yak-authored and findings without calling the classifier', function (array $pullRequest, ?string $findingSeverity, string $reason) {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification()]);
    $review = previewReview();
    if ($findingSeverity !== null) {
        PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => $findingSeverity, 'resolution_status' => 'fixed']);
    }

    $outcome = app(ReviewApprovalPolicy::class)->preview($this->repo, previewPullRequest(overrides: $pullRequest), previewFiles(), $review->load('comments'), null);

    expect($outcome['holds'])->toContain($reason);
    ConcernClassifier::assertNeverPrompted();
})->with([
    'draft' => [['draft' => true], null, 'Pull request is not open, is a draft, has auto-merge on, or comes from a fork.'],
    'yak' => [['user' => ['login' => 'yak[bot]']], null, 'Yak opened this pull request.'],
    'findings' => [[], 'should_fix', 'Review contains findings.'],
]);

it('holds a repository without required checks only as a repository hold and skips the classifier', function () {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification()]);
    $this->github->shouldReceive('hasRequiredStatusChecks')->andReturn(false);
    $policy = app(ReviewApprovalPolicy::class);

    $outcome = $policy->preview($this->repo, previewPullRequest(), previewFiles(), previewReview(), null);

    expect($outcome['holds'])->toBe([])->and($outcome['changeKind'])->toBeNull()
        ->and($policy->repositoryHolds($this->repo))->toBe(['Branch protection on main has no required status checks.']);
    ConcernClassifier::assertNeverPrompted();
});

it('classifies once with two model calls when nothing else holds and reports the change kind', function () {
    Ai::fakeAgent(ConcernClassifier::class, [
        ['touched_concerns' => [], 'change_kind' => 'behavior_change', 'is_uncertain' => false],
        ['touched_concerns' => [], 'change_kind' => 'behavior_change', 'is_uncertain' => false],
    ]);

    $outcome = app(ReviewApprovalPolicy::class)->preview($this->repo, previewPullRequest(), previewFiles(), previewReview(), null);

    expect($outcome['holds'])->toBe([])->and($outcome['changeKind'])->toBe('behavior_change')->and($outcome['classification']['change_kind'])->toBe('behavior_change');
    ConcernClassifier::assertPrompted(fn () => true);
});

it('reuses the stored classification at the same concerns version without calling the classifier', function () {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification('behavior_change')]);
    $this->github->shouldReceive('getPullRequestOrFail')->andReturn(previewPullRequest());
    $this->github->shouldReceive('listPullRequestFilesOrFail')->andReturn(previewFiles());
    previewReview(assessment: ['concerns_version' => $this->repo->settings()->concernsVersion(), 'classification' => previewClassification()]);
    $preview = ApprovalPreview::factory()->create(['repository_id' => $this->repo->id, 'pr_number' => 7, 'head_sha' => 'sha7']);

    (new CheckPullRequestApprovalJob($preview->id))->handle($this->github, app(ReviewApprovalPolicy::class));

    expect($preview->refresh()->status)->toBe('done')->and($preview->result['changeKind'])->toBe('presentation_or_internal')
        ->and($preview->result['waiting'])->toBeFalse();
    ConcernClassifier::assertNeverPrompted();
});

it('ignores a stored classification from another concerns version', function () {
    Ai::fakeAgent(ConcernClassifier::class, [previewClassification('behavior_change'), previewClassification('behavior_change')]);
    $this->github->shouldReceive('getPullRequestOrFail')->andReturn(previewPullRequest());
    $this->github->shouldReceive('listPullRequestFilesOrFail')->andReturn(previewFiles());
    previewReview(assessment: ['concerns_version' => 'stale', 'classification' => previewClassification()]);
    $preview = ApprovalPreview::factory()->create(['repository_id' => $this->repo->id, 'pr_number' => 7, 'head_sha' => 'sha7']);

    (new CheckPullRequestApprovalJob($preview->id))->handle($this->github, app(ReviewApprovalPolicy::class));

    expect($preview->refresh()->result['changeKind'])->toBe('behavior_change');
});

it('marks the row failed when GitHub errors', function () {
    $this->github->shouldReceive('getPullRequestOrFail')->andThrow(new RuntimeException('GitHub 500'));
    $preview = ApprovalPreview::factory()->create(['repository_id' => $this->repo->id, 'pr_number' => 7]);

    (new CheckPullRequestApprovalJob($preview->id))->handle($this->github, app(ReviewApprovalPolicy::class));

    expect($preview->refresh()->status)->toBe('failed')->and($preview->error)->toBe('GitHub 500');
});

it('shapes the open pull request prop and reloads it alone', function () {
    $user = User::factory()->create();
    $inertia = fn (?string $only = null): array => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())]
        + ($only === null ? [] : ['X-Inertia-Partial-Component' => 'Repositories/Approvals', 'X-Inertia-Partial-Data' => $only]);

    $this->actingAs($user)->get(route('repos.approvals.show', $this->repo), $inertia())
        ->assertJsonPath('props.openPullRequests', ['checkedAt' => null, 'pending' => 0, 'rows' => []])
        ->assertJsonPath('props.repositoryHolds', []);

    ApprovalPreview::factory()->done()->create(['repository_id' => $this->repo->id, 'pr_number' => 7, 'head_sha' => 'sha7']);
    ApprovalPreview::factory()->create(['repository_id' => $this->repo->id, 'pr_number' => 8]);
    $this->actingAs($user)->get(route('repos.approvals.show', $this->repo), $inertia('openPullRequests'))
        ->assertJsonPath('props.openPullRequests.pending', 1)
        ->assertJsonPath('props.openPullRequests.rows.0.number', 7)
        ->assertJsonPath('props.openPullRequests.rows.0.status', 'done')
        ->assertJsonMissingPath('props.repositoryHolds');
});

it('dispatches the check and requests a review from the routes', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('repos.approvals.check', $this->repo))->assertRedirect();
    Queue::assertPushed(CheckOpenPullRequestApprovalsJob::class);

    $this->github->shouldReceive('getPullRequestOrFail')->andReturn(['state' => 'closed']);
    $this->actingAs($user)->post(route('repos.approvals.review', [$this->repo, 7]))->assertSessionHas('error');
});
