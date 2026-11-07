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
     * Publishes `yak.app_disk.used` and `yak.app_disk.total` in bytes, read
     * when telemetry flushes. A path PHP cannot stat publishes no sample.
     */
    public function registerGauges(): void
    {
        CboxTelemetry::gauge(
            'yak.app_disk.used',
            function (): ?float {
                $total = @disk_total_space(storage_path());
                $free = @disk_free_space(storage_path());

                return $total === false || $free === false ? null : $total - $free;
            },
            description: 'Bytes used on the disk that holds Yak storage',
            unit: 'By',
        );

        CboxTelemetry::gauge(
            'yak.app_disk.total',
            fn (): ?float => ($total = @disk_total_space(storage_path())) === false ? null : $total,
            description: 'Size of the disk that holds Yak storage',
            unit: 'By',
        );
    }
}
