<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\Models\Repository;

/**
 * Opens pull requests that add `.yak/` files, once per fixed branch.
 */
class ConfigPullRequests
{
    /** Branches Yak writes `.yak/` files to, in the order a pending PR is looked up. */
    private const PENDING_BRANCHES = ['yak/config-migration', 'yak/setup-config'];

    public function __construct(private AppService $github) {}

    /**
     * Returns the open PR for the branch when there is one, and writes nothing.
     * Otherwise commits the files to the branch and opens a labelled PR.
     *
     * @param  array<string, string>  $files  repository path => content
     * @return array{number: int, url: string, created: bool}
     */
    public function open(Repository $repository, string $branch, array $files, string $title, string $body, string $commitMessage, bool $updateExisting = false): array
    {
        $installationId = $this->installationId();
        $slug = $repository->github_full_name;

        $existing = $this->github->findOpenPullRequestForBranch($installationId, $slug, $branch);

        if ($existing !== null && $updateExisting) {
            $this->github->createBranchWithFiles($installationId, $slug, $repository->default_branch, $branch, $files, $commitMessage);
            $this->github->updatePullRequest($installationId, $slug, (int) $existing['number'], ['body' => $body]);

            return ['number' => (int) $existing['number'], 'url' => (string) $existing['html_url'], 'created' => false];
        }

        if ($existing !== null) {
            return ['number' => (int) $existing['number'], 'url' => (string) $existing['html_url'], 'created' => false];
        }

        $this->github->createBranchWithFiles($installationId, $slug, $repository->default_branch, $branch, $files, $commitMessage);

        $pullRequest = $this->github->createPullRequest($installationId, $slug, [
            'title' => $title,
            'body' => $body,
            'head' => $branch,
            'base' => $repository->default_branch,
        ]);

        $this->github->addLabels($installationId, $slug, (int) $pullRequest['number'], ['yak']);

        return ['number' => (int) $pullRequest['number'], 'url' => (string) $pullRequest['html_url'], 'created' => true];
    }

    /** @return array{number: int, title: string, url: string}|null */
    public function openPullRequest(Repository $repository): ?array
    {
        $installationId = $this->installationId();

        foreach (self::PENDING_BRANCHES as $branch) {
            $pullRequest = $this->github->findOpenPullRequestForBranch($installationId, $repository->github_full_name, $branch);

            if ($pullRequest !== null) {
                return [
                    'number' => (int) $pullRequest['number'],
                    'title' => (string) $pullRequest['title'],
                    'url' => (string) $pullRequest['html_url'],
                ];
            }
        }

        return null;
    }

    private function installationId(): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            throw new \RuntimeException('GitHub App is not configured');
        }

        return $installationId;
    }
}
