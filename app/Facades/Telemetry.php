<?php

namespace App\Facades;

use App\Services\Telemetry\Telemetry as TelemetryService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static void record(string $name, array<string, mixed> $properties = [], ?\App\Models\YakTask $task = null, ?string $repo = null, ?string $source = null, ?int $durationMs = null, ?float $value = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?int $runId = null, ?\DateTimeInterface $occurredAt = null)
 * @method static mixed time(string $name, \Closure $callback, array<string, mixed> $properties = [], ?\App\Models\YakTask $task = null, ?\Illuminate\Database\Eloquent\Model $subject = null)
 * @method static void feature(string $feature, array<string, mixed> $properties = [], ?\App\Models\YakTask $task = null, ?string $repo = null, ?string $source = null)
 *
 * @see TelemetryService
 */
class Telemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelemetryService::class;
    }
}
