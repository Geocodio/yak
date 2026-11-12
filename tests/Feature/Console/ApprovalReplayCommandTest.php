<?php

use App\Ai\Agents\ConcernClassifier;
use App\Channels\GitHub\AppService;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TelemetryEvent;
use App\Models\YakTask;
use App\Services\RepositoryConfigParser;
use Laravel\Ai\Ai;

function replayReview(Repository $repository, int $prNumber): PrReview
{
    $task = YakTask::factory()->create(['repo' => $repository->slug]);
    TelemetryEvent::create([
        'name' => 'review.submitted', 'yak_task_id' => $task->id, 'occurred_at' => now(),
        'properties' => ['severities' => ['must_fix' => 0, 'should_fix' => 0, 'consider' => 0], 'prior_findings' => 0],
    ]);

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
