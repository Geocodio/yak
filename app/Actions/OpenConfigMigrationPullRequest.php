<?php

namespace App\Actions;

use App\Models\Repository;
use App\Services\ConfigPullRequests;
use App\Services\RepositoryConfig;
use App\Services\YakConfigFiles;

/**
 * Opens the pull request that moves a repository's stored settings into
 * `.yak/`, used by both `yak:migrate-config` and the settings page button.
 */
class OpenConfigMigrationPullRequest
{
    public const BRANCH = 'yak/config-migration';

    public const TITLE = 'Move Yak settings into .yak/';

    private const FILE_SOURCES = [
        '.yak/config.yml' => 'Description, CI system, public site URL, PR review settings',
        '.yak/preview.yml' => 'Branch deployment manifest',
        '.yak/AGENTS.md' => 'Agent instructions',
    ];

    public function __construct(
        private readonly YakConfigFiles $configFiles,
        private readonly ConfigPullRequests $pullRequests,
        private readonly RepositoryConfig $config,
    ) {}

    /**
     * @return array{number: int, url: string, created: bool}
     *
     * @throws \RuntimeException when `.yak/` cannot be read or the repository already has `.yak/` files
     */
    public function handle(Repository $repository): array
    {
        $this->config->forget($repository);
        $snapshot = $this->config->snapshot($repository);

        if ($snapshot->state !== 'read') {
            throw new \RuntimeException("Could not read .yak/ for {$repository->slug} from GitHub; try again once the settings page shows the current config.");
        }

        if ($snapshot->files !== []) {
            throw new \RuntimeException("{$repository->slug} already has .yak/ files");
        }

        $migration = $this->configFiles->forMigration($repository);

        $sources = [];
        foreach (array_keys($migration['files']) as $path) {
            $sources[$path] = self::FILE_SOURCES[$path]
                ?? ($migration['riskProfileUnapproved'] ? "Risk profile draft from {$migration['riskProfileDate']} (see the warning below)" : 'Approved risk profile');
        }

        $body = view('pull-requests.config-migration', [
            'repository' => $repository,
            'files' => $sources,
            'riskProfileUnapproved' => $migration['riskProfileUnapproved'],
            'riskProfileDate' => $migration['riskProfileDate'],
            'checksLostAppPin' => $migration['checksLostAppPin'],
        ])->render();

        return $this->pullRequests->open($repository, self::BRANCH, $migration['files'], self::TITLE, $body, self::TITLE);
    }
}
