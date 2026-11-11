<?php

use App\Channels\GitHub\AppService;
use App\Models\PrReview;

function shadowReview(int $prNumber, string $candidate, array $reasons = []): PrReview
{
    return PrReview::factory()->create([
        'repo' => 'geocodio/api',
        'pr_number' => $prNumber,
        'commit_sha_reviewed' => "sha-{$prNumber}",
        'created_at' => now()->subDay(),
        'risk_assessment' => ['mode' => 'shadow', 'candidate' => $candidate, 'reasons' => $reasons],
    ]);
}

it('flags a would-be approval that a human later changed and passes a clean one', function () {
    shadowReview(1, 'APPROVE');
    shadowReview(2, 'APPROVE');
    shadowReview(3, 'COMMENT', ['Profile requires human review: app/Billing/Invoice.php', 'Review contains findings.']);
    PrReview::factory()->create(['repo' => 'geocodio/api', 'risk_assessment' => ['mode' => 'off', 'candidate' => 'COMMENT']]);

    $this->mock(AppService::class, function ($mock) {
        $mock->shouldReceive('appBotLogin')->andReturn('yak[bot]');
        $mock->shouldReceive('getPullRequest')->andReturnUsing(fn ($installation, $repo, $number) => ['state' => 'closed', 'merged_at' => '2026-11-09T10:00:00Z']);
        $mock->shouldReceive('listPullRequestCommits')->andReturnUsing(fn ($installation, $repo, $number) => $number === 1
            ? [['sha' => 'sha-1'], ['sha' => 'human-fix']]
            : [['sha' => 'sha-2']]);
        $mock->shouldReceive('listPullRequestReviews')->andReturnUsing(fn ($installation, $repo, $number) => [
            ['state' => 'CHANGES_REQUESTED', 'user' => ['login' => 'yak[bot]'], 'submitted_at' => now()->toIso8601String()],
        ]);
    });

    $this->artisan('yak:approval-report')
        ->expectsTable(['Repository', 'Reviews', 'Approve', 'Request changes', 'Comment'], [['geocodio/api', 3, 2, 0, 1]])
        ->expectsTable(['Top reasons Yak held a review for a human', 'Reviews'], [
            ['Profile requires human review', 1],
            ['Review contains findings.', 1],
        ])
        ->expectsTable(['Would-be approval', 'Outcome', 'Commits after review', 'Human changes requested'], [
            ['https://github.com/geocodio/api/pull/1', 'needs a look', '1', 'no'],
            ['https://github.com/geocodio/api/pull/2', 'clean merge', '0', 'no'],
        ])
        ->expectsOutputToContain('2 would-be approvals, 1 needed a human change')
        ->assertSuccessful();
});
