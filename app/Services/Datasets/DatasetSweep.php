<?php

namespace App\Services\Datasets;

use App\Enums\DatasetVersionStatus;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use Illuminate\Support\Facades\Process;

/**
 * Self-heals dataset state after a crash: clones whose container is gone are
 * released, and superseded snapshots nobody clones any more are deleted.
 */
class DatasetSweep
{
    public function __construct(
        private readonly DatasetVolumes $volumes,
        private readonly DatasetVersions $versions,
    ) {}

    /** Returns how many clones and snapshots were reclaimed. */
    public function run(): int
    {
        $reclaimed = 0;

        DatasetClone::whereNull('destroyed_at')->get()->each(function (DatasetClone $clone) use (&$reclaimed): void {
            if ($this->containerExists($clone->container_name)) {
                return;
            }

            $this->volumes->deleteVolume($clone->volume_name);
            $clone->update(['destroyed_at' => now()]);
            $this->versions->releaseClone($clone);
            $reclaimed++;
        });

        DatasetVersion::where('status', DatasetVersionStatus::Ready)->with('dataset')->get()->each(function (DatasetVersion $version) use (&$reclaimed): void {
            if ($version->id === $version->dataset->current_version_id) {
                return;
            }

            $this->versions->retireSuperseded($version->dataset);

            if ($version->fresh()?->status === DatasetVersionStatus::Retired) {
                $reclaimed++;
            }
        });

        return $reclaimed;
    }

    private function containerExists(string $container): bool
    {
        return Process::timeout(30)->run(['incus', 'info', $container])->successful();
    }
}
