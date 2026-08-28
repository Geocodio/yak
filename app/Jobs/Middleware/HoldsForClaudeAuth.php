<?php

namespace App\Jobs\Middleware;

use App\Services\HealthCheck\ClaudeAuthCheck;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Holds agent jobs in the queue while the shared Claude session is unusable.
 *
 * Without this a task builds a sandbox, fails to authenticate about fifteen
 * seconds in, and dies terminally. Holding it instead means the work drains
 * on its own once an operator re-authenticates.
 *
 * The flag is set and cleared by ClaudeAuthCheck's liveness probe.
 */
class HoldsForClaudeAuth
{
    public const RELEASE_DELAY_SECONDS = 60;

    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        if (Cache::get(ClaudeAuthCheck::UNUSABLE_CACHE_KEY) && method_exists($job, 'release')) {
            $job->release(self::RELEASE_DELAY_SECONDS);

            return;
        }

        $next($job);
    }
}
