<?php

use App\Services\GitHubAppService;
use App\Services\PullRequestBodyUpdater;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 77);
});

test('appends director cut link to existing Video walkthrough section, preserving reviewer link', function () {
    $existing = "## Yak Automated PR\n\nSome summary\n\n### Video walkthrough\n\n- [reviewer-cut.mp4](https://example.com/reviewer.mp4)\n\n### Files changed\n\n- `foo.php`";

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
                && str_contains($data['body'], "Director's Cut")
                && str_contains($data['body'], 'director.mp4')
                && str_contains($data['body'], 'reviewer.mp4')
                && str_contains($data['body'], '### Files changed');
        })
        ->andReturn(['body' => 'ok']);

    $updater = new PullRequestBodyUpdater($github);
    $updater->appendDirectorCut(
        repoFullName: 'owner/repo',
        prNumber: 42,
        directorCutUrl: 'https://example.com/director.mp4',
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
                && str_contains($data['body'], "Director's Cut")
                && str_contains($data['body'], 'director.mp4');
        })
        ->andReturn(['body' => 'ok']);

    $updater = new PullRequestBodyUpdater($github);
    $updater->appendDirectorCut('owner/repo', 42, 'https://example.com/director.mp4');
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
                && str_contains($data['body'], "Director's Cut");
        })
        ->andReturn(['body' => 'ok']);

    $updater = new PullRequestBodyUpdater($github);
    $updater->appendDirectorCut('owner/repo', 42, 'https://example.com/director.mp4');
});

test('is idempotent when Director\'s Cut link already present', function () {
    $existing = "### Video walkthrough\n\n- [reviewer-cut.mp4](https://example.com/reviewer.mp4)\n- [▶ Watch Director's Cut](https://example.com/director.mp4)\n";

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')
        ->once()
        ->andReturn(['body' => $existing]);

    $github->shouldNotReceive('updatePullRequest');

    $updater = new PullRequestBodyUpdater($github);
    $updater->appendDirectorCut('owner/repo', 42, 'https://example.com/director.mp4');
});
