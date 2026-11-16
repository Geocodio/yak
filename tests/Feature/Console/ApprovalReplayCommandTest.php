<?php

use App\Ai\Agents\ConcernClassifier;
use App\Channels\GitHub\AppService;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\RepositoryConfigFile;
use App\Models\TelemetryEvent;
use App\Models\YakTask;
use App\Services\RepositoryConfig;
use App\Services\RepositoryConfigParser;
use Laravel\Ai\Ai;

function replayReview(Repository $repository, int $prNumber, int $priorFindings = 0, bool $hasTelemetry = true): PrReview
{
    $task = YakTask::factory()->create(['repo' => $repository->slug]);
    if ($hasTelemetry) {
        TelemetryEvent::create([
            'name' => 'review.submitted', 'yak_task_id' => $task->id, 'occurred_at' => now(),
            'properties' => ['severities' => ['must_fix' => 0, 'should_fix' => 0, 'consider' => 0], 'prior_findings' => $priorFindings],
        ]);
    }

    return PrReview::factory()->create([
        'yak_task_id' => $task->id, 'repo' => $repository->slug, 'pr_number' => $prNumber,
        'verdict' => 'Approve', 'commit_sha_reviewed' => "head-{$prNumber}",
        'risk_assessment' => ['mode' => 'shadow', 'candidate' => 'COMMENT', 'head_sha' => "head-{$prNumber}", 'base_sha' => "base-{$prNumber}"],
    ]);
}

it('replays stored reviews through the policy without writing or posting anything', function () {
    configureFakeGitHubApp();
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'approval_level' => 3, 'pr_review_enabled' => true, 'approval_mode' => 'shadow']);
    replayReview($repository, 1);
    replayReview($repository, 2);
    $reviewCount = PrReview::count();

    $this->mock(AppService::class, function ($mock) {
        $mock->shouldReceive('appBotLogin')->andReturn('yak[bot]');
        $mock->shouldReceive('getPullRequestOrFail')->andReturnUsing(fn ($installation, $repo, $number) => [
            'title' => "PR {$number}", 'user' => ['login' => 'human'], 'base' => ['ref' => 'main'],
            'state' => 'closed', 'draft' => true,
        ]);
        $mock->shouldReceive('compareFilesOrFail')->andReturn([['filename' => 'src/a.php', 'status' => 'modified', 'patch' => '@@ -1 +1 @@']]);
        $mock->shouldNotReceive('hasRequiredStatusChecks');
        $mock->shouldNotReceive('createPullRequestReview');
        $mock->shouldNotReceive('dismissPullRequestReview');
    });

    $concernName = array_column((new RepositoryConfigParser)->parse('risk-profile.yml', concernsYaml())['data']['concerns'], 'name')[0];
    Ai::fakeAgent(ConcernClassifier::class, [
        ['touched_concerns' => [['name' => $concernName, 'evidence' => 'x']], 'change_kind' => 'behavior_change', 'is_uncertain' => false],
        ['touched_concerns' => [['name' => $concernName, 'evidence' => 'x']], 'change_kind' => 'behavior_change', 'is_uncertain' => false],
        ['touched_concerns' => [], 'change_kind' => 'no_runtime_change', 'is_uncertain' => false],
        ['touched_concerns' => [], 'change_kind' => 'no_runtime_change', 'is_uncertain' => false],
    ]);

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug])
        ->expectsTable(['PR', 'Title', 'Candidate', 'Change kind', 'Touched concerns', 'First reason'], [
            ['#2', 'PR 2', 'COMMENT', 'behavior_change', $concernName, "Always needs a human: {$concernName}"],
            ['#1', 'PR 1', 'APPROVE', 'no_runtime_change', '-', '-'],
        ])
        ->expectsTable(['Candidate', 'PRs'], [['APPROVE', 1], ['COMMENT', 1]])
        ->expectsTable(['Touched concern', 'PRs'], [[$concernName, 1]])
        ->assertSuccessful();

    expect(PrReview::count())->toBe($reviewCount)
        ->and($repository->fresh()->approval_level)->toBe(3);
});

function replayMockGitHub(string $changeKind = 'no_runtime_change'): void
{
    test()->mock(AppService::class, function ($mock) {
        $mock->shouldReceive('appBotLogin')->andReturn('yak[bot]');
        $mock->shouldReceive('getPullRequestOrFail')->andReturnUsing(fn ($installation, $repo, $number) => [
            'title' => "PR {$number}", 'user' => ['login' => 'human'], 'base' => ['ref' => 'main'],
        ]);
        $mock->shouldReceive('compareFilesOrFail')->andReturn([['filename' => 'src/a.php', 'status' => 'modified', 'patch' => '@@ -1 +1 @@']]);
    });
}

/** @param list<array{name: string, evidence: string}> $touched */
function replayClassifier(int $pullRequests, string $changeKind = 'no_runtime_change', array $touched = []): void
{
    Ai::fakeAgent(ConcernClassifier::class, array_fill(0, $pullRequests * 2, ['touched_concerns' => $touched, 'change_kind' => $changeKind, 'is_uncertain' => false]));
}

beforeEach(function () {
    configureFakeGitHubApp();
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
});

it('never touches the repository row or config files and restores the container bindings', function () {
    $repository = Repository::factory()->create([
        'github_full_name' => 'o/r', 'approval_level' => 3, 'is_active' => false, 'pr_review_enabled' => false,
        'config_commit_sha' => 'stale-sha',
    ]);
    replayReview($repository, 1);
    replayMockGitHub();
    replayClassifier(1);
    $before = $repository->fresh()->getAttributes();
    $configFilesBefore = RepositoryConfigFile::count();
    $github = app(AppService::class);
    $config = app(RepositoryConfig::class);

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug])->assertSuccessful();

    expect($repository->fresh()->getAttributes())->toBe($before)
        ->and(RepositoryConfigFile::count())->toBe($configFilesBefore)
        ->and(app(AppService::class))->toBe($github)
        ->and(app(RepositoryConfig::class))->toBe($config);
});

it('lets --level override the repository level for one run only', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'approval_level' => 2, 'pr_review_enabled' => true]);
    replayReview($repository, 1);
    replayMockGitHub();

    replayClassifier(1, 'behavior_change');
    $this->artisan('yak:approval-replay', ['slug' => $repository->slug])
        ->expectsTable(['Candidate', 'PRs'], [['COMMENT', 1]])->assertSuccessful();

    replayClassifier(1, 'behavior_change');
    $this->artisan('yak:approval-replay', ['slug' => $repository->slug, '--level' => 5])
        ->expectsTable(['Candidate', 'PRs'], [['APPROVE', 1]])->assertSuccessful();

    expect($repository->fresh()->approval_level)->toBe(2);
});

it('rejects an invalid level and an invalid limit', function (string $option, string $value) {
    $repository = Repository::factory()->create(['github_full_name' => 'o/r']);

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug, $option => $value])->assertFailed();
})->with([['--level', '9'], ['--level', 'x'], ['--limit', '0'], ['--limit', 'abc']]);

it('uses a local version 2 concerns file and rejects unreadable or version 1 files', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'approval_level' => 5, 'pr_review_enabled' => true]);
    replayReview($repository, 1);
    replayMockGitHub();
    $path = tempnam(sys_get_temp_dir(), 'concerns');
    file_put_contents($path, "version: 2\nconcerns:\n  - name: Local only concern\n    approval: always_human\n    description: A concern from the local file.\n");
    replayClassifier(1, 'behavior_change', [['name' => 'Local only concern', 'evidence' => 'x']]);

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug, '--concerns' => $path])
        ->expectsTable(['PR', 'Title', 'Candidate', 'Change kind', 'Touched concerns', 'First reason'], [
            ['#1', 'PR 1', 'COMMENT', 'behavior_change', 'Local only concern', 'Always needs a human: Local only concern'],
        ])->assertSuccessful();

    file_put_contents($path, riskProfileYaml());
    $this->artisan('yak:approval-replay', ['slug' => $repository->slug, '--concerns' => $path])->assertFailed();
    $this->artisan('yak:approval-replay', ['slug' => $repository->slug, '--concerns' => $path . '.missing'])->assertFailed();
    unlink($path);
});

it('reports a review without telemetry as unavailable', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'approval_level' => 3, 'pr_review_enabled' => true]);
    replayReview($repository, 1, hasTelemetry: false);
    replayMockGitHub();

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug])
        ->expectsTable(['PR', 'Title', 'Candidate', 'Change kind', 'Touched concerns', 'First reason'], [
            ['#1', '-', 'UNAVAILABLE', '-', '-', 'Stored review is missing its base commit or telemetry.'],
        ])->assertSuccessful();
});

it('replays only the newest reviews up to --limit', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'approval_level' => 3, 'pr_review_enabled' => true]);
    foreach ([1, 2, 3] as $number) {
        replayReview($repository, $number);
    }
    replayMockGitHub();
    replayClassifier(2);

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug, '--limit' => 2])
        ->expectsTable(['PR', 'Title', 'Candidate', 'Change kind', 'Touched concerns', 'First reason'], [
            ['#3', 'PR 3', 'APPROVE', 'no_runtime_change', '-', '-'],
            ['#2', 'PR 2', 'APPROVE', 'no_runtime_change', '-', '-'],
        ])->assertSuccessful();
});

it('holds a review that stored prior findings', function () {
    $repository = Repository::factory()->create(['github_full_name' => 'o/r', 'approval_level' => 3, 'pr_review_enabled' => true]);
    replayReview($repository, 1, priorFindings: 2);
    replayMockGitHub();

    $this->artisan('yak:approval-replay', ['slug' => $repository->slug])
        ->expectsTable(['PR', 'Title', 'Candidate', 'Change kind', 'Touched concerns', 'First reason'], [
            ['#1', 'PR 1', 'COMMENT', '-', '-', 'An earlier finding is still open.'],
        ])->assertSuccessful();
});
