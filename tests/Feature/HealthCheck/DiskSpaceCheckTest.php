<?php

use App\Services\HealthCheck\DiskSpaceCheck;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Process;

function fakeDiskUsage(int $usedPercent): void
{
    Process::fake([
        'df -Pk /' => Process::result(
            "Filesystem     1024-blocks      Used Available Capacity Mounted on\n"
            . "overlay          457179136 400000000  52428800      {$usedPercent}% /\n",
        ),
    ]);
}

it('reports the used percentage and free space', function (int $usedPercent, HealthStatus $status) {
    fakeDiskUsage($usedPercent);

    $result = (new DiskSpaceCheck)->run();

    expect($result->status)->toBe($status)
        ->and($result->detail)->toStartWith("{$usedPercent}% used — 50 GB free");
})->with([
    'plenty of room' => [58, HealthStatus::Ok],
    'getting full' => [85, HealthStatus::Warn],
    'nearly full' => [95, HealthStatus::Error],
]);

it('errors when df fails', function () {
    Process::fake(['df -Pk /' => Process::result(errorOutput: 'df: /: No such file', exitCode: 1)]);

    expect((new DiskSpaceCheck)->run()->status)->toBe(HealthStatus::Error);
});
