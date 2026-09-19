<?php

namespace App\Console\Commands;

use App\Models\Repository;
use App\Services\RepositoryRiskProfiles;
use Illuminate\Console\Command;

class RiskProfileCommand extends Command
{
    protected $signature = 'yak:risk-profile {slug} {--import= : Local JSON draft edited by a human} {--approve= : Exact draft SHA-256 reviewed by a human} {--reviewer= : Human reviewer identity}';

    protected $description = 'Draft a repository risk profile, or activate an explicitly reviewed draft';

    public function handle(RepositoryRiskProfiles $profiles): int
    {
        $repository = Repository::where('slug', $this->argument('slug'))->first();
        if ($repository === null || ! $repository->is_active) {
            $this->error('Repository not found or inactive.');

            return self::FAILURE;
        }
        if ($this->option('import') !== null) {
            if ($this->option('approve') !== null) {
                $this->error('Import and approval must be separate invocations.');

                return self::FAILURE;
            }
            try {
                $path = (string) $this->option('import');
                if (! is_file($path) || ! is_readable($path) || filesize($path) > 1048576) {
                    throw new \RuntimeException('Import must be a readable local JSON file smaller than 1 MiB.');
                }
                $json = file_get_contents($path);
                if ($json === false) {
                    throw new \RuntimeException('Could not read the profile import.');
                }
                $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
                $draft = $profiles->draft($repository->slug, (string) ($data['source_sha'] ?? ''), $json);
                $this->info('Saved new draft ' . $draft['version'] . '. Review before activating.');

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }
        if ($this->option('approve') !== null) {
            try {
                $profile = $profiles->approve($repository->slug, (string) $this->option('approve'), (string) $this->option('reviewer'));
                $this->info('Activated reviewed risk profile ' . $profile['version']);

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }
        $task = $profiles->generate($repository);
        $this->info("Risk profile research queued as task #{$task->id}. This only creates a draft.");

        return self::SUCCESS;
    }
}
