<?php

namespace App\Services\HealthCheck;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis holds every queue, so workers that keep restarting against a dead
 * Redis still look alive to the queue worker check. This asks Redis itself.
 */
class RedisCheck implements HealthCheck
{
    public function id(): string
    {
        return 'redis';
    }

    public function name(): string
    {
        return 'Redis';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        try {
            Redis::connection()->ping();
        } catch (Throwable $exception) {
            return HealthResult::error('Unreachable: ' . $exception->getMessage());
        }

        return HealthResult::ok('Reachable');
    }
}
