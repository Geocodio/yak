<?php

use App\Services\GitHubAppService;
use App\Services\PullRequestBodyUpdater;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 77);
});

test('swaps the raw webm fallback link for the rendered reviewer cut', function () {
    $existing = "## Yak Automated PR\n\nSome summary\n\n### Video walkthrough\n\n- [walkthrough.webm](https://signed.example/webm?exp=1)\n\n### Files changed\n\n- `foo.php`";

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')
        ->once()
        ->with(77, 'owner/repo', 42)
        ->andReturn(['body' => $existing]);

    $github->shouldReceive('updatePullRequest')
        ->once()
        ->withArgs(function (int $installationId, string $repo, int $num, array $data): bool {
            return $installationId === 77
                && $repo === 'owner/repo'
                && $num === 42
                && str_contains($data['body'], '[reviewer-cut.mp4](https://signed.example/mp4)')
                && ! str_contains($data['body'], 'walkthrough.webm')
                && str_contains($data['body'], '### Files changed');
        })
        ->andReturn(['body' => 'ok']);

    $updater = new PullRequestBodyUpdater($github);
    $updater->setReviewerCut(
        repoFullName: 'owner/repo',
        prNumber: 42,
        reviewerCutUrl: 'https://signed.example/mp4',
    );
});

test('creates the Video walkthrough section if missing', function () {
    $existing = "## Summary\n\nNo walkthrough yet\n\n### Files changed\n\n- `foo.php`";

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')
        ->once()
        ->with(77, 'owner/repo', 42)
        ->andReturn(['body' => $existing]);

    $github->shouldReceive('updatePullRequest')
        ->once()
        ->withArgs(function (int $installationId, string $repo, int $num, array $data): bool {
            return str_contains($data['body'], '### Video walkthrough')
                && str_contains($data['body'], '[reviewer-cut.mp4](https://signed.example/mp4)');
        })
        ->andReturn(['body' => 'ok']);

    $updater = new PullRequestBodyUpdater($github);
    $updater->setReviewerCut('owner/repo', 42, 'https://signed.example/mp4');
});

test('handles empty body by creating the Video walkthrough section', function () {
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')
        ->once()
        ->andReturn(['body' => null]);

    $github->shouldReceive('updatePullRequest')
        ->once()
        ->withArgs(function ($installationId, $repo, $num, array $data): bool {
            return str_contains($data['body'], '### Video walkthrough')
                && str_contains($data['body'], '[reviewer-cut.mp4]');
        })
        ->andReturn(['body' => 'ok']);

    $updater = new PullRequestBodyUpdater($github);
    $updater->setReviewerCut('owner/repo', 42, 'https://signed.example/mp4');
});

test('is idempotent when the reviewer cut filename is already linked', function () {
    $existing = "### Video walkthrough\n\n- [reviewer-cut.mp4](https://signed.example/old?exp=1)\n";

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')
        ->once()
        ->andReturn(['body' => $existing]);

    $github->shouldNotReceive('updatePullRequest');

    $updater = new PullRequestBodyUpdater($github);
    // New signed URL for the same filename — must still no-op.
    $updater->setReviewerCut('owner/repo', 42, 'https://signed.example/new?exp=2');
});
