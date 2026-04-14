<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Services\GitHubAppService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:sync-repo-descriptions {--force : Overwrite existing descriptions}')]
#[Description('Sync repository descriptions from GitHub for routing context')]
class SyncRepoDescriptions extends Command
{
    public function handle(GitHubAppService $github): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            $this->components->error('No GitHub installation configured.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $query = Repository::where('is_active', true);
        if (! $force) {
            $query->whereNull('description');
        }

        $repos = $query->get();

        if ($repos->isEmpty()) {
            $this->components->info('No repositories to sync.');

            return self::SUCCESS;
        }

        foreach ($repos as $repo) {
            $info = $github->getRepository($installationId, $repo->slug);

            if ($info === null) {
                $this->components->warn("Could not fetch {$repo->slug}");

                continue;
            }

            $repo->update(['description' => $info['description']]);
            $this->components->info("Updated {$repo->slug}: " . ($info['description'] ?? '(no description)'));
        }

        return self::SUCCESS;
    }
}
