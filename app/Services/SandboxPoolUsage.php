<?php

namespace App\Services;

use Cbox\Telemetry\Facades\Telemetry as CboxTelemetry;
use Illuminate\Support\Facades\Process;

/**
 * How full the Incus storage pool that holds every sandbox is.
 *
 * The pool is ZFS (a dedicated disk or a file-backed image), so an
 * ordinary filesystem check cannot see how much of it is used. Incus
 * reports it through its REST API.
 */
class SandboxPoolUsage
{
    /**
     * The Incus storage pool the sandboxes' root disks use. It sits on the
     * `incus-pool` zpool, but Incus knows it as `default` (see the incus
     * Ansible role and the default profile's root device).
     */
    public const POOL_NAME = 'default';

    private const QUERY_TIMEOUT = 5;

    /**
     * Publishes `yak.sandbox_pool.usage` in bytes with a `state` label
     * (`used`, `total`), read when telemetry flushes. A pool Incus cannot
     * report publishes no sample, so the chart shows no data rather than
     * a zero.
     */
    public function registerGauges(): void
    {
        CboxTelemetry::gauge(
            'yak.sandbox_pool.usage',
            function (): array {
                $usage = $this->current();

                return $usage === null ? [] : [
                    [$usage['usedBytes'], ['state' => 'used']],
                    [$usage['totalBytes'], ['state' => 'total']],
                ];
            },
            description: 'Bytes used and size of the Incus storage pool that holds the sandboxes',
            unit: 'By',
        );
    }

    /**
     * @return array{usedBytes: int, totalBytes: int}|null
     */
    public function current(): ?array
    {
        return $this->pool(self::POOL_NAME);
    }

    /**
     * Every Incus storage pool on the host, keyed by name. A host can have
     * one pool or several, each backed by any number of disks; Incus reports
     * each pool's own size, so the disk layout does not matter here. A host
     * without Incus, or a pool Incus cannot report, contributes nothing.
     *
     * @return array<string, array{usedBytes: int, totalBytes: int}>
     */
    public function all(): array
    {
        $result = Process::timeout(self::QUERY_TIMEOUT)->run('incus query /1.0/storage-pools 2>/dev/null');

        if (! $result->successful()) {
            return [];
        }

        $paths = json_decode($result->output(), true);

        if (! is_array($paths)) {
            return [];
        }

        $pools = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $name = rawurldecode(basename($path));
            $usage = $this->pool($name);

            if ($usage !== null) {
                $pools[$name] = $usage;
            }
        }

        return $pools;
    }

    /**
     * @return array{usedBytes: int, totalBytes: int}|null
     */
    private function pool(string $name): ?array
    {
        $result = Process::timeout(self::QUERY_TIMEOUT)
            ->run('incus query /1.0/storage-pools/' . rawurlencode($name) . '/resources 2>/dev/null');

        if (! $result->successful()) {
            return null;
        }

        $space = json_decode($result->output(), true)['space'] ?? null;

        if (! is_array($space) || ! is_numeric($space['used'] ?? null) || ! is_numeric($space['total'] ?? null) || (int) $space['total'] <= 0) {
            return null;
        }

        return [
            'usedBytes' => (int) $space['used'],
            'totalBytes' => (int) $space['total'],
        ];
    }
}
