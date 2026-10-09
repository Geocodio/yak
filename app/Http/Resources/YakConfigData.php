<?php

namespace App\Http\Resources;

use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\RepositorySettings;
use App\Models\Repository;
use App\Services\ConfigPullRequests;
use App\Services\RepositoryConfigParser;

/**
 * Shapes a {@see ConfigSnapshot} for the repository settings page. A `values`
 * entry is non-null only when a `.yak/` file supplies it, which includes a file
 * whose newest commit is invalid but which has a last valid version.
 */
final class YakConfigData
{
    /**
     * @return array{
     *     state: 'read'|'unreachable'|'unavailable',
     *     setupStatus: string,
     *     commitSha: ?string,
     *     commitUrl: ?string,
     *     readAt: ?string,
     *     readError: ?string,
     *     directoryUrl: ?string,
     *     configPullRequest: array{number: int, title: string, url: string}|null,
     *     files: list<array{
     *         name: string,
     *         valid: bool,
     *         error: ?string,
     *         validCommitSha: ?string,
     *         errorCommitSha: ?string,
     *         errorCommitUrl: ?string,
     *         errorPullRequest: array{number: int, title: string, url: string}|null,
     *         blobUrl: ?string,
     *         editUrl: ?string,
     *     }>,
     *     values: array{
     *         description: ?string,
     *         agentInstructions: ?string,
     *         publicSiteUrl: ?string,
     *         ciSystem: ?string,
     *         prReviewEnabled: ?bool,
     *         pathExcludes: list<string>|null,
     *         reviewPolicy: array<string, mixed>|null,
     *         previewManifest: array<string, mixed>|null,
     *         previewScript: bool,
     *         riskProfile: array{areas: int, unknowns: int, commitSha: ?string}|null,
     *     },
     * }
     */
    public static function from(Repository $repository, ConfigSnapshot $snapshot): array
    {
        $githubUrl = $repository->githubUrl();
        $branch = $repository->default_branch;
        $commitUrl = fn (?string $sha): ?string => $githubUrl !== null && $sha !== null ? "{$githubUrl}/commit/{$sha}" : null;

        $files = [];
        foreach (RepositoryConfigParser::FILES as $name) {
            $file = $snapshot->file($name);
            if ($file === null) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'valid' => $file->isValid(),
                'error' => $file->error,
                'validCommitSha' => $file->validCommitSha,
                'errorCommitSha' => $file->errorCommitSha,
                'errorCommitUrl' => $commitUrl($file->errorCommitSha),
                'errorPullRequest' => $file->errorPullRequest,
                'blobUrl' => $githubUrl !== null ? "{$githubUrl}/blob/{$branch}/.yak/{$name}" : null,
                'editUrl' => $githubUrl !== null ? "{$githubUrl}/edit/{$branch}/.yak/{$name}" : null,
            ];
        }

        $config = is_array($snapshot->data('config.yml')) ? $snapshot->data('config.yml') : [];
        $manifest = $snapshot->data('preview.yml');
        $riskProfile = $snapshot->data('risk-profile.yml');
        $instructions = $snapshot->data('AGENTS.md');

        return [
            'state' => match ($snapshot->state) {
                'read' => 'read',
                'unreachable' => 'unreachable',
                default => 'unavailable',
            },
            'setupStatus' => (string) $repository->setup_status,
            'commitSha' => $snapshot->commitSha,
            'commitUrl' => $commitUrl($snapshot->commitSha),
            'readAt' => $snapshot->readAt?->toIso8601String(),
            'readError' => $snapshot->readError,
            'directoryUrl' => $githubUrl !== null ? "{$githubUrl}/tree/{$branch}/.yak" : null,
            'configPullRequest' => self::configPullRequest($repository, $files === []),
            'files' => $files,
            'values' => [
                'description' => $config['description'] ?? null,
                'agentInstructions' => is_string($instructions) ? $instructions : null,
                'publicSiteUrl' => $config['walkthrough']['public_site_url'] ?? null,
                'ciSystem' => $config['ci'] ?? null,
                'prReviewEnabled' => $config['review']['enabled'] ?? null,
                'pathExcludes' => $config['review']['exclude_paths'] ?? null,
                'reviewPolicy' => isset($config['review']['approval'])
                    ? (new RepositorySettings($repository, $snapshot))->reviewPolicy()
                    : null,
                'reviewApproval' => is_array($config['review']['approval'] ?? null) ? $config['review']['approval'] : null,
                'previewManifest' => is_array($manifest) ? $manifest : null,
                'previewScript' => $snapshot->data('preview.sh') !== null,
                'riskProfile' => is_array($riskProfile) ? [
                    'areas' => count($riskProfile['areas'] ?? []),
                    'unknowns' => count($riskProfile['unknowns'] ?? []),
                    'commitSha' => $snapshot->file('risk-profile.yml')?->validCommitSha,
                ] : null,
            ],
        ];
    }

    /**
     * Only looked up while the repository has no `.yak/` files, because the
     * pull request matters only until it merges. A GitHub failure gives null.
     *
     * @return array{number: int, title: string, url: string}|null
     */
    private static function configPullRequest(Repository $repository, bool $hasNoFiles): ?array
    {
        if (! $hasNoFiles) {
            return null;
        }

        try {
            return app(ConfigPullRequests::class)->openPullRequest($repository);
        } catch (\Throwable) {
            return null;
        }
    }
}
