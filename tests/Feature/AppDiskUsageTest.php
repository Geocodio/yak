<?php

use App\Services\AppDiskUsage;
use Cbox\Telemetry\Facades\Telemetry as CboxTelemetry;

it('publishes used and total bytes of the disk that holds storage', function () {
    $telemetry = CboxTelemetry::fake();

    (new AppDiskUsage)->registerGauges();

    $total = disk_total_space(storage_path());

    expect($telemetry->gaugeValue('yak.app_disk.usage', ['state' => 'total']))->toBe((float) $total)
        ->and($telemetry->gaugeValue('yak.app_disk.usage', ['state' => 'used']))->toBeGreaterThan(0.0)
        ->and($telemetry->gaugeValue('yak.app_disk.usage', ['state' => 'used']))->toBeLessThanOrEqual((float) $total);
});
