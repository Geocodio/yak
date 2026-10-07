<?php

namespace App\Services\HealthCheck;

use Illuminate\Support\Facades\Process;

/**
 * Watches the host's root disk, which the container's root filesystem sits
 * on. When it fills, workers crash while writing logs and running tasks hang
 * without a trace, so this warns while there is still room to act.
 */
class DiskSpaceCheck implements HealthCheck
{
    private const WARN_PERCENT = 85;

    private const ERROR_PERCENT = 95;

    public function id(): string
    {
        return 'disk-space';
    }

    public function name(): string
    {
        return 'Disk Space';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $result = Process::timeout(5)->run('df -Pk /');

        if (! $result->successful() || ! preg_match('/\s(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+\/\s*$/m', $result->output(), $matches)) {
            return HealthResult::error('Could not read disk usage');
        }

        $usedPercent = (int) $matches[4];
        $freeGigabytes = round((int) $matches[3] / 1024 / 1024, 1);
        $detail = "{$usedPercent}% used — {$freeGigabytes} GB free";

        if ($usedPercent >= self::ERROR_PERCENT) {
            return HealthResult::error("{$detail}. Prune old Docker images with `docker image prune -f` on the host");
        }

        if ($usedPercent >= self::WARN_PERCENT) {
            return HealthResult::warn($detail);
        }

        return HealthResult::ok($detail);
    }
}
