<?php

namespace App\Services\HealthCheck;

use Illuminate\Support\Facades\Redis;

/**
 * Verifies Redis answers and the pipe broker is running. The broker refreshes
 * a short-lived heartbeat key every second while it serves; thread turns
 * cannot start without it.
 */
class BrokerCheck implements HealthCheck
{
    public function id(): string
    {
        return 'broker';
    }

    public function name(): string
    {
        return 'Pipe Broker and Redis';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        try {
            $connection = Redis::connection('threads');
            $connection->ping();
        } catch (\Throwable $e) {
            return HealthResult::error('Redis is not reachable: ' . $e->getMessage());
        }

        if (! $connection->exists('broker:alive')) {
            return HealthResult::error('Redis answers but the pipe broker is not running — check `systemctl status yak-broker`');
        }

        return HealthResult::ok('Redis and the pipe broker are up');
    }
}
