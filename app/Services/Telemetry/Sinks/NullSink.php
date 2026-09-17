<?php

namespace App\Services\Telemetry\Sinks;

use App\Services\Telemetry\Contracts\TelemetrySink;

/**
 * Bound when yak.telemetry.enabled is false.
 */
class NullSink implements TelemetrySink
{
    public function write(array $row): void {}
}
