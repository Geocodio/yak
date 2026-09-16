<?php

namespace App\Services\Telemetry\Sinks;

use App\Models\TelemetryEvent;
use App\Services\Telemetry\Contracts\TelemetrySink;

/**
 * Single-row insert into telemetry_events. Deliberately not queued: the
 * queue is database-backed, so a job would cost more than the insert.
 */
class DatabaseSink implements TelemetrySink
{
    public function write(array $row): void
    {
        $row['properties'] = $row['properties'] === null
            ? null
            : json_encode($row['properties'], JSON_THROW_ON_ERROR);

        TelemetryEvent::query()->insert($row);
    }
}
