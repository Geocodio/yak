<?php

use App\Models\TelemetryEvent;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\TelemetryCheck;
use App\Services\Telemetry\Telemetry;

test('reports ok with no events yet', function () {
    $result = (new TelemetryCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toContain('no events recorded yet');
});

test('reports the event count, last event age and retention when collecting', function () {
    TelemetryEvent::factory()->count(3)->create(['occurred_at' => now()->subMinutes(5)]);

    $result = (new TelemetryCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toContain('3 events')
        ->and($result->detail)->toContain('90-day retention');
});

test('warns when telemetry is disabled', function () {
    config(['yak.telemetry.enabled' => false]);
    app()->forgetInstance(Telemetry::class);

    $result = (new TelemetryCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warn)
        ->and($result->detail)->toContain('YAK_TELEMETRY_ENABLED=false');
});
