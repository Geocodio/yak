<?php

namespace App\Services\HealthCheck;

final class HealthResult
{
    public function __construct(
        public readonly HealthStatus $status,
        public readonly string $detail,
        public readonly ?HealthAction $action = null,
    ) {}

    public static function ok(string $detail): self
    {
        return new self(HealthStatus::Ok, $detail);
    }

    public static function error(string $detail, ?HealthAction $action = null): self
    {
        return new self(HealthStatus::Error, $detail, $action);
    }

    public static function warn(string $detail): self
    {
        return new self(HealthStatus::Warn, $detail);
    }

    public static function notConnected(string $detail, ?HealthAction $action = null): self
    {
        return new self(HealthStatus::NotConnected, $detail, $action);
    }
}
