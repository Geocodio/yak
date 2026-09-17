<?php

namespace App\Services\Telemetry\Contracts;

/**
 * Where telemetry events go. The database sink is the only one shipped;
 * the shape of a row is deliberately OpenTelemetry-like (name, timestamp,
 * duration, attribute bag) so an OTLP or log sink can be added without
 * touching any call site.
 */
interface TelemetrySink
{
    /**
     * @param  array{
     *     occurred_at: \DateTimeInterface,
     *     name: string,
     *     repo: string|null,
     *     source: string|null,
     *     yak_task_id: int|null,
     *     task_run_id: int|null,
     *     subject_type: string|null,
     *     subject_id: int|null,
     *     duration_ms: int|null,
     *     value: float|null,
     *     properties: array<string, mixed>|null
     * }  $row
     */
    public function write(array $row): void;
}
