<?php

namespace App\Services;

use Cbox\Telemetry\Facades\Telemetry as CboxTelemetry;

/**
 * How full the disk that holds Yak's storage is.
 *
 * cboxdk/system-metrics adds up every mounted filesystem, which counts the
 * same host disk once per bind mount inside the Yak container. This reads
 * the one filesystem behind storage_path() instead.
 */
class AppDiskUsage
{
    /**
     * Publishes `yak.app_disk.usage` in bytes with a `state` label (`used`,
     * `total`), read when telemetry flushes. A path PHP cannot stat
     * publishes no sample.
     */
    public function registerGauges(): void
    {
        CboxTelemetry::gauge(
            'yak.app_disk.usage',
            function (): array {
                $total = @disk_total_space(storage_path());
                $free = @disk_free_space(storage_path());

                if ($total === false || $free === false) {
                    return [];
                }

                return [
                    [$total - $free, ['state' => 'used']],
                    [$total, ['state' => 'total']],
                ];
            },
            description: 'Bytes used and size of the disk that holds Yak storage',
            unit: 'By',
        );
    }
}
