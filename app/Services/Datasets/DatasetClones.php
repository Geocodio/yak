<?php

namespace App\Services\Datasets;

use App\Exceptions\DatasetCommandFailed;
use App\Models\BranchDeployment;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\Repository;
use App\Models\Thread;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives a thread sandbox or a preview its own copy-on-write clone of the
 * current dataset version and removes it again with the sandbox. A repository
 * without a fetched dataset costs no incus call.
 */
class DatasetClones
{
    public function __construct(
        private readonly DatasetVolumes $volumes,
        private readonly DatasetVersions $versions,
    ) {}

    public function attach(Thread|BranchDeployment $owner, Repository $repository, string $container): ?DatasetClone
    {
        $dataset = Dataset::where('repository_id', $repository->id)->first();

        if ($dataset === null || $dataset->current_version_id === null || ! $this->ownsDataset($owner, $repository)) {
            return null;
        }

        $existing = $this->rowFor($owner);

        if ($existing !== null && $existing->destroyed_at === null) {
            return $existing;
        }

        $version = $dataset->currentVersion;
        $cloneVolume = DatasetClone::volumeNameFor($owner);

        try {
            $this->volumes->clone($dataset->volume_name, $version->version, $cloneVolume);
            $this->volumes->attach($container, $cloneVolume, $dataset->mount_path);

            return DatasetClone::updateOrCreate(
                ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()],
                ['dataset_version_id' => $version->id, 'volume_name' => $cloneVolume, 'container_name' => $container, 'destroyed_at' => null],
            );
        } catch (Throwable $failure) {
            Log::warning('Dataset clone failed; the sandbox starts without the dataset', [
                'owner' => $owner->getMorphClass() . ':' . $owner->getKey(),
                'error' => $this->volumes->scrub($failure->getMessage()),
            ]);
            $this->deleteVolumeQuietly($cloneVolume);

            return null;
        }
    }

    public function destroyFor(Thread|BranchDeployment $owner): void
    {
        $clone = $this->rowFor($owner);

        if ($clone === null || $clone->destroyed_at !== null) {
            return;
        }

        try {
            $this->volumes->detach($clone->container_name);
        } catch (DatasetCommandFailed) {
            // The container is already gone, which removed the device with it.
        }

        $this->volumes->deleteVolume($clone->volume_name);
        $clone->update(['destroyed_at' => now()]);
        $this->versions->releaseClone($clone);
    }

    private function rowFor(Thread|BranchDeployment $owner): ?DatasetClone
    {
        return DatasetClone::where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())->first();
    }

    /** A multi-repo thread gets the primary repository's dataset only. */
    private function ownsDataset(Thread|BranchDeployment $owner, Repository $repository): bool
    {
        if ($owner instanceof BranchDeployment) {
            return true;
        }

        $primary = $owner->primaryRepository?->repo ?? $owner->repo;

        return $primary === $repository->slug;
    }

    private function deleteVolumeQuietly(string $volume): void
    {
        try {
            $this->volumes->deleteVolume($volume);
        } catch (Throwable) {
            // Best effort; the sweep reclaims a stranded volume.
        }
    }
}
