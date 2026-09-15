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
 *
 * Every release increments the job's `attempts` column, an unsigned tiny
 * integer that overflows past 255. The delay keeps a job held for its whole
 * retryUntil() window well under that limit, and an expired session needs a
 * person to fix it anyway, so a slower retry loses nothing.
 */
class HoldsForClaudeAuth
{
    public const RELEASE_DELAY_SECONDS = 600;

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
