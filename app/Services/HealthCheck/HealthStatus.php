<?php

namespace App\Services\HealthCheck;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Error = 'error';
    case NotConnected = 'not_connected';

    public function isHealthy(): bool
    {
        return $this === self::Ok || $this === self::NotConnected;
    }
}
