<?php

namespace App\Services\HealthCheck;

final class HealthAction
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
    ) {}
}
