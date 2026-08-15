<?php

namespace App\Console\Commands;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:sync-github-repo-identity {--dry-run : Report changes without writing them}')]
#[Description("Record each repository's immutable GitHub id and current owner/name, healing past renames")]
class SyncGitHubRepoIdentity extends Command
{
    public function handle(GitHubAppService $github): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            $this->components->error('No GitHub installation configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $repositories = Repository::all();

        if ($repositories->isEmpty()) {
            $this->components->info('No repositories to sync.');

            return self::SUCCESS;
        }

        $changed = 0;
        $unreachable = 0;

        foreach ($repositories as $repository) {
            // A request for a renamed repository's old path is redirected to
            // its current one, so the stale name we hold is enough to
            // rediscover where the repository lives now.
            $current = $github->getRepositoryIdentity($installationId, $repository->github_full_name);

            if ($current === null) {
                $unreachable++;
                $this->components->warn("Could not resolve {$repository->github_full_name} (slug: {$repository->slug})");

                continue;
            }

            $updates = [];

            if ($repository->github_repo_id !== $current['id']) {
                $updates['github_repo_id'] = $current['id'];
            }

            if ($repository->github_full_name !== $current['full_name']) {
                $updates['github_full_name'] = $current['full_name'];

                if ($current['clone_url'] !== null) {
                    $updates['git_url'] = $current['clone_url'];
                }
            }

            if ($updates === []) {
                continue;
            }

            $changed++;

            $description = isset($updates['github_full_name'])
                ? "{$repository->github_full_name} → {$current['full_name']}"
                : "{$repository->github_full_name} (id {$current['id']})";

            $this->components->twoColumnDetail($repository->slug, $description);

            if (! $dryRun) {
                $repository->update($updates);
            }
        }

        $verb = $dryRun ? 'would update' : 'updated';
        $this->components->info("{$verb} {$changed} of {$repositories->count()} repositories ({$unreachable} unreachable).");

        return self::SUCCESS;
    }
}
