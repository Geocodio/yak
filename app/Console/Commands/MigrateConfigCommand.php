<?php

namespace App\Console\Commands;

use App\Actions\OpenConfigMigrationPullRequest;
use App\Models\Repository;
use App\Services\RepositoryConfig;
use App\Services\YakConfigFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:migrate-config {--repo= : Only migrate this repository slug} {--dry-run : List the files without opening pull requests}')]
#[Description('Open a pull request per active repository that moves its stored settings into .yak/')]
class MigrateConfigCommand extends Command
{
    public function handle(OpenConfigMigrationPullRequest $migrate, YakConfigFiles $configFiles, RepositoryConfig $config): int
    {
        $repositories = Repository::query()
            ->where('is_active', true)
            ->when($this->option('repo'), fn ($query, string $slug) => $query->where('slug', $slug))
            ->get();

        $failed = false;

        foreach ($repositories as $repository) {
            if ($config->snapshot($repository)->files !== []) {
                $this->components->warn("{$repository->slug} already has .yak/ files");

                continue;
            }

            try {
                if ($this->option('dry-run')) {
                    $this->components->info($repository->slug . ': ' . implode(', ', array_keys($configFiles->forMigration($repository)['files'])));

                    continue;
                }

                $pullRequest = $migrate->handle($repository);
                $this->components->info("{$repository->slug}: {$pullRequest['url']}" . ($pullRequest['created'] ? '' : ' (already open)'));
            } catch (\Throwable $exception) {
                $failed = true;
                $this->components->error("{$repository->slug}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
