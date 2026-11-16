<?php

use App\Services\HealthCheck\DiskSpaceCheck;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Process;

/**
 * @param  array<string, array{used: int, total: int}>  $pools
 */
function fakeDiskUsage(int $usedPercent, array $pools = []): void
{
    $fakes = [
        'df -Pk /' => Process::result(
            "Filesystem     1024-blocks      Used Available Capacity Mounted on\n"
            . "overlay          457179136 400000000  52428800      {$usedPercent}% /\n",
        ),
    ];

    foreach ($pools as $name => $space) {
        $fakes["incus query /1.0/storage-pools/{$name}/resources*"] = Process::result(json_encode(['space' => $space]));
    }

    $fakes['incus query /1.0/storage-pools *'] = $pools === []
        ? Process::result(output: '', errorOutput: 'incus: command not found', exitCode: 127)
        : Process::result(json_encode(array_map(fn (string $name) => "/1.0/storage-pools/{$name}", array_keys($pools))));

    Process::fake($fakes);
}

it('reports the root disk alone on a host without incus', function (int $usedPercent, HealthStatus $status) {
    fakeDiskUsage($usedPercent);

    $result = (new DiskSpaceCheck)->run();

    expect($result->status)->toBe($status)
        ->and($result->detail)->toStartWith("Root: {$usedPercent}% used, 50.0 GB free");
})->with([
    'plenty of room' => [58, HealthStatus::Ok],
    'getting full' => [85, HealthStatus::Warn],
    'nearly full' => [95, HealthStatus::Error],
]);

it('reports every incus pool next to the root disk', function () {
    $terabyte = 1024 ** 4;

    fakeDiskUsage(17, [
        'default' => ['used' => (int) ($terabyte * 0.03), 'total' => $terabyte * 1.5],
        'scratch' => ['used' => $terabyte, 'total' => $terabyte * 4],
    ]);

    $result = (new DiskSpaceCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toBe(
            'Root: 17% used, 50.0 GB free · Sandbox pool default: 2% used, 1.5 TB free · Sandbox pool scratch: 25% used, 3.0 TB free',
        );
});

it('takes its status from the fullest disk', function (int $poolPercent, HealthStatus $status) {
    fakeDiskUsage(17, ['default' => ['used' => $poolPercent, 'total' => 100]]);

    expect((new DiskSpaceCheck)->run()->status)->toBe($status);
})->with([
    'pool getting full' => [90, HealthStatus::Warn],
    'pool nearly full' => [97, HealthStatus::Error],
]);

it('points at the sandboxes when a pool is full', function () {
    fakeDiskUsage(17, ['default' => ['used' => 97, 'total' => 100]]);

    expect((new DiskSpaceCheck)->run()->detail)->toContain('incus delete')->not->toContain('docker image prune');
});

it('errors when df fails', function () {
    Process::fake(['df -Pk /' => Process::result(errorOutput: 'df: /: No such file', exitCode: 1)]);

    expect((new DiskSpaceCheck)->run()->status)->toBe(HealthStatus::Error);
});
