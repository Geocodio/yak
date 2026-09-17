<?php

namespace App\Services\HealthCheck;

use App\Facades\Telemetry;
use App\Models\TelemetryEvent;
use Carbon\Carbon;

class TelemetryCheck implements HealthCheck
{
    public function id(): string
    {
        return 'telemetry';
    }

    public function name(): string
    {
        return 'Telemetry';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        if (! Telemetry::enabled()) {
            return HealthResult::warn('Disabled (YAK_TELEMETRY_ENABLED=false) — the Analytics page has no data');
        }

        $latest = TelemetryEvent::query()->latest('occurred_at')->first();

        if ($latest === null) {
            return HealthResult::ok('Enabled — no events recorded yet');
        }

        $count = TelemetryEvent::query()->count();
        $ago = Carbon::parse($latest->occurred_at)->diffForHumans();
        $days = (int) config('yak.telemetry.retention_days', 90);

        return HealthResult::ok("Enabled — {$count} events, last {$ago}, {$days}-day retention");
    }
}
