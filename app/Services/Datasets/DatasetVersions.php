<?php

namespace App\Services\Datasets;

use App\Enums\DatasetStatus;
use App\Enums\DatasetVersionStatus;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;

/**
 * Publishes fetched versions and keeps at most two of them snapshotted: the
 * current one plus the previous one while sandboxes still clone it.
 */
class DatasetVersions
{
    public function __construct(private readonly DatasetVolumes $volumes) {}

    public function publish(DatasetVersion $version): void
    {
        $dataset = $version->dataset;

        $this->volumes->snapshot($dataset->volume_name, $version->version);

        $version->update(['status' => DatasetVersionStatus::Ready, 'fetched_at' => now()]);
        $dataset->update([
            'status' => DatasetStatus::Ready,
            'current_version_id' => $version->id,
            'last_fetched_at' => now(),
            'last_error' => null,
        ]);

        $this->retireSuperseded($dataset);
    }

    public function retireSuperseded(Dataset $dataset): void
    {
        $dataset->versions()
            ->where('status', DatasetVersionStatus::Ready)
            ->where('id', '!=', $dataset->current_version_id)
            ->get()
            ->each(fn (DatasetVersion $old) => $this->retireWhenUnused($old));
    }

    public function canStartFetch(Dataset $dataset): bool
    {
        $clonedReadyVersions = $dataset->versions()
            ->where('status', DatasetVersionStatus::Ready)
            ->whereHas('liveClones')
            ->count();

        return $clonedReadyVersions < 2;
    }

    public function releaseClone(DatasetClone $clone): void
    {
        $version = $clone->version;

        if ($version->status === DatasetVersionStatus::Ready && $version->id !== $version->dataset->current_version_id) {
            $this->retireWhenUnused($version);
        }
    }

    private function retireWhenUnused(DatasetVersion $version): void
    {
        if ($version->status !== DatasetVersionStatus::Ready || $version->liveClones()->exists()) {
            return;
        }

        $this->volumes->deleteSnapshot($version->dataset->volume_name, $version->version);
        $version->update(['status' => DatasetVersionStatus::Retired, 'retired_at' => now()]);
    }
}
