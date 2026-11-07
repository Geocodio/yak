<?php

use App\Services\SandboxPoolUsage;
use Cbox\Telemetry\Facades\Telemetry as CboxTelemetry;
use Illuminate\Support\Facades\Process;

it('reads used and total bytes from incus', function () {
    Process::fake([
        'incus query /1.0/storage-pools/incus-pool/resources*' => Process::result('{"space":{"used":73014444032,"total":107374182400}}'),
    ]);

    expect((new SandboxPoolUsage)->current())->toBe([
        'usedBytes' => 73014444032,
        'totalBytes' => 107374182400,
    ]);
});

it('returns null when incus fails', function () {
    Process::fake([
        'incus query*' => Process::result(output: '', errorOutput: 'Error: not found', exitCode: 1),
    ]);

    expect((new SandboxPoolUsage)->current())->toBeNull();
});

it('returns null when the output is not the expected shape', function () {
    Process::fake([
        'incus query*' => Process::result('{}'),
    ]);

    expect((new SandboxPoolUsage)->current())->toBeNull();
});

it('publishes used and total bytes as gauges', function () {
    $telemetry = CboxTelemetry::fake();
    Process::fake([
        'incus query*' => Process::result('{"space":{"used":73014444032,"total":107374182400}}'),
    ]);

    (new SandboxPoolUsage)->registerGauges();

    expect($telemetry->gaugeValue('yak.sandbox_pool.used'))->toBe(73014444032.0)
        ->and($telemetry->gaugeValue('yak.sandbox_pool.total'))->toBe(107374182400.0);
});

it('publishes no pool gauges when incus cannot answer', function () {
    $telemetry = CboxTelemetry::fake();
    Process::fake([
        'incus query*' => Process::result(output: '', exitCode: 1),
    ]);

    (new SandboxPoolUsage)->registerGauges();

    $telemetry->assertGaugeNotSet('yak.sandbox_pool.used');
    $telemetry->assertGaugeNotSet('yak.sandbox_pool.total');
});
