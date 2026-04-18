<?php

namespace App\Console\Commands;

use App\Jobs\Middleware\PausesDuringDrain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('yak:resume')]
#[Description('Clear the drain flag so queue workers resume picking up agent jobs')]
class ResumeAfterDeployCommand extends Command
{
    public function handle(): int
    {
        Cache::forget(PausesDuringDrain::CACHE_KEY);

        $this->components->info('Drain flag cleared — workers will resume picking up tasks.');

        return self::SUCCESS;
    }
}
