<?php

use App\Ai\Agents\SameCauseCheck;
use App\Channels\GitHub\AppService;
use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Services\CiFailure\CiFailureQualifier;

function qualifierRepository(): Repository
{
    return Repository::factory()->create([
        'slug' => 'acme/widgets',
        'github_full_name' => 'acme/widgets',
        'default_branch' => 'main',
    ]);
}

function fakeQualifierGitHub(bool $isAncestor = true, ?string $defaultHead = 'default-head', ?string $excerpt = 'boom'): void
{
    test()->mock(AppService::class, function ($mock) use ($isAncestor, $defaultHead, $excerpt) {
        $mock->shouldReceive('getBranchHeadSha')->andReturn($defaultHead);
        $mock->shouldReceive('compareCommits')->andReturn(['status' => 'diverged', 'merge_base_sha' => 'merge-base']);
        $mock->shouldReceive('isAncestor')->andReturn($isAncestor);
        $mock->shouldReceive('getJobLogExcerpt')->andReturn($excerpt);
    });
}

function qualifyFailure(CiJobFailure $failure)
{
    return app(CiFailureQualifier::class)->qualify(qualifierRepository(), $failure);
}

it('qualifies the first red run on the default branch', function () {
    fakeQualifierGitHub();
    $failure = CiJobFailure::factory()->create(['branch' => 'main', 'job_name' => 'Static Analysis']);

    $result = qualifyFailure($failure);

    expect($result->qualifies)->toBeTrue()
        ->and($result->reason)->toBe('default_branch')
        ->and($result->evidence->pluck('id')->all())->toBe([$failure->id]);
});

it('defers a test job seen only on the default branch to the flaky scan', function () {
    fakeQualifierGitHub();
    $failure = CiJobFailure::factory()->create(['branch' => 'main', 'job_name' => 'Test (Main)']);

    $result = qualifyFailure($failure);

    expect($result->qualifies)->toBeFalse()
        ->and($result->reason)->toBe('deferred_to_flaky_scan');
});

it('does not qualify a single red branch', function () {
    fakeQualifierGitHub();
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/a']);

    $result = qualifyFailure($failure);

    expect($result->qualifies)->toBeFalse()->and($result->reason)->toBe('single_branch');
});

it('qualifies the same job and step red on two unrelated branches', function () {
    fakeQualifierGitHub();
    $other = CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_name' => 'Static Analysis']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'job_name' => 'Static Analysis']);

    $result = qualifyFailure($failure);

    expect($result->qualifies)->toBeTrue()
        ->and($result->reason)->toBe('cross_branch')
        ->and($result->evidence->pluck('id')->sort()->values()->all())->toBe([$other->id, $failure->id]);
});

it('rejects two branches whose merge base is not on the default branch', function () {
    fakeQualifierGitHub(isAncestor: false);
    CiJobFailure::factory()->create(['branch' => 'feature/a']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b']);

    $result = qualifyFailure($failure);

    expect($result->qualifies)->toBeFalse()->and($result->reason)->toBe('shared_parent');
});

it('keeps candidates when the default branch head is unknown', function () {
    fakeQualifierGitHub(isAncestor: false, defaultHead: null);
    CiJobFailure::factory()->create(['branch' => 'feature/a']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b']);

    expect(qualifyFailure($failure)->reason)->toBe('cross_branch');
});

it('ignores failures outside the correlation window', function () {
    fakeQualifierGitHub();
    config(['yak.ci_failure.correlation_window_hours' => 72]);
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'failed_at' => now()->subHours(73)]);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b']);

    expect(qualifyFailure($failure)->reason)->toBe('single_branch');
});

it('ignores a candidate with the same head sha', function () {
    fakeQualifierGitHub();
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'head_sha' => 'same']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'head_sha' => 'same']);

    expect(qualifyFailure($failure)->reason)->toBe('single_branch');
});

it('drops branch failures older than the latest default-branch green', function () {
    fakeQualifierGitHub();
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'failed_at' => now()->subHours(5)]);
    CiDefaultBranchGreen::factory()->create(['completed_at' => now()->subHours(2)]);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'failed_at' => now()]);

    expect(qualifyFailure($failure)->reason)->toBe('single_branch');
});

it('reports a failure older than the default-branch green as stale', function () {
    fakeQualifierGitHub();
    CiDefaultBranchGreen::factory()->create(['completed_at' => now()]);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'failed_at' => now()->subHour()]);

    $result = qualifyFailure($failure);

    expect($result->qualifies)->toBeFalse()->and($result->reason)->toBe('stale_after_green');
});

it('correlates test jobs by failing test across differently named jobs', function () {
    fakeQualifierGitHub();
    CiJobFailure::factory()->create([
        'branch' => 'feature/a',
        'job_name' => 'Test (Shard 1)',
        'test_names' => ['FooTest::it_works', 'BarTest::it_runs'],
    ]);
    $failure = CiJobFailure::factory()->create([
        'branch' => 'feature/b',
        'job_name' => 'Test (Shard 2)',
        'test_names' => ['BarTest::it_runs'],
    ]);

    expect(qualifyFailure($failure)->reason)->toBe('cross_branch');
});

it('does not correlate test jobs with disjoint failing tests', function () {
    fakeQualifierGitHub();
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'test_names' => ['FooTest::a']]);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'test_names' => ['BarTest::b']]);

    expect(qualifyFailure($failure)->reason)->toBe('single_branch');
});

it('requires the same failed step for non-test jobs', function () {
    fakeQualifierGitHub();
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_name' => 'Lint', 'failed_step' => 'Install']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'job_name' => 'Lint', 'failed_step' => 'Run pint']);

    expect(qualifyFailure($failure)->reason)->toBe('single_branch');
});

it('declines when the same-cause check says different causes', function () {
    fakeQualifierGitHub();
    $this->mock(SameCauseCheck::class, fn ($mock) => $mock->shouldReceive('shareCause')->once()->andReturn(false));
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_name' => 'Lint']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'job_name' => 'Lint']);

    expect(qualifyFailure($failure)->reason)->toBe('different_causes');
});

it('counts two branches by the same author', function () {
    fakeQualifierGitHub();
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'actor_login' => 'octocat']);
    $failure = CiJobFailure::factory()->create(['branch' => 'feature/b', 'actor_login' => 'octocat']);

    expect(qualifyFailure($failure)->qualifies)->toBeTrue();
});
