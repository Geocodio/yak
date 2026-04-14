<?php

namespace App\Services\HealthCheck;

interface HealthCheck
{
    public function id(): string;

    public function name(): string;

    public function section(): HealthSection;

    public function run(): HealthResult;
}
