<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Process;

class CleanupDevEnvironment
{
    public function __construct(
        private string $repoPath,
    ) {}

    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } finally {
            Process::path($this->repoPath)->run('docker-compose stop');
        }
    }
}
