<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Holds long-running agent jobs back while a deploy is draining.
 *
 * The `yak:drain` command sets the cache flag before a container
 * recreate so in-flight tasks can finish while new work stays queued
 * instead of starting on a worker that's about to be SIGKILLed.
 *
 * Applied only to jobs on the `yak-claude` queue — short default-queue
 * jobs finish inside supervisord's stopwaitsecs window without help.
 */
class PausesDuringDrain
{
    public const CACHE_KEY = 'yak:draining';

    public const RELEASE_DELAY_SECONDS = 60;

    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        if (Cache::has(self::CACHE_KEY) && method_exists($job, 'release')) {
            $job->release(self::RELEASE_DELAY_SECONDS);

            return;
        }

        $next($job);
    }
}
