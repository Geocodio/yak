<?php

namespace App\Services\HealthCheck;

use App\Services\SandboxPoolUsage;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;

/**
 * Watches the host's root disk, which the container's root filesystem sits
 * on, and every Incus storage pool, which holds the sandboxes. Either one
 * filling up stalls tasks, so this warns while there is still room to act.
 * The fullest of them sets the status.
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

        $rootPercent = (int) $matches[4];
        $parts = [$this->describe('Root', $rootPercent, (int) $matches[3] * 1024)];
        $worstPercent = $rootPercent;
        $hint = $rootPercent >= self::ERROR_PERCENT
            ? 'Prune old Docker images with `docker image prune -f` on the host'
            : null;

        foreach (app(SandboxPoolUsage::class)->all() as $name => $pool) {
            $poolPercent = (int) round($pool['usedBytes'] / $pool['totalBytes'] * 100);
            $parts[] = $this->describe("Sandbox pool {$name}", $poolPercent, $pool['totalBytes'] - $pool['usedBytes']);

            if ($poolPercent >= self::ERROR_PERCENT) {
                $hint ??= 'Delete unused sandboxes and templates with `incus list` / `incus delete` on the host';
            }

            $worstPercent = max($worstPercent, $poolPercent);
        }

        $detail = implode(' · ', $parts);

        if ($worstPercent >= self::ERROR_PERCENT) {
            return HealthResult::error($hint === null ? $detail : "{$detail}. {$hint}");
        }

        if ($worstPercent >= self::WARN_PERCENT) {
            return HealthResult::warn($detail);
        }

        return HealthResult::ok($detail);
    }

    private function describe(string $label, int $usedPercent, int $freeBytes): string
    {
        return "{$label}: {$usedPercent}% used, " . Number::fileSize($freeBytes, precision: 1) . ' free';
    }
}
