<?php

namespace App\Console\Commands;

use App\GitOperations;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('yak:refresh-repos')]
#[Description('Fetch latest changes from origin for all active repositories')]
class RefreshReposCommand extends Command
{
    public function handle(): int
    {
        $repositories = Repository::where('is_active', true)->get();

        foreach ($repositories as $repository) {
            try {
                GitOperations::refreshRepo($repository);
                $this->components->info("Refreshed {$repository->slug}");
            } catch (\Throwable $e) {
                Log::warning("Failed to refresh repo {$repository->slug}", [
                    'error' => $e->getMessage(),
                ]);
                $this->components->error("Failed to refresh {$repository->slug}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
