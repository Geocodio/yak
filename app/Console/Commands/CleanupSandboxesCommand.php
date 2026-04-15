<?php

namespace App\Console\Commands;

use App\Services\IncusSandboxManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:cleanup-sandboxes')]
#[Description('Delete leftover sandbox containers from crashed/aborted tasks')]
class CleanupSandboxesCommand extends Command
{
    public function handle(IncusSandboxManager $sandbox): int
    {
        $deleted = $sandbox->cleanupStale();

        $this->components->info("Removed {$deleted} stale sandbox container(s)");

        return self::SUCCESS;
    }
}
