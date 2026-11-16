<?php

use App\Enums\DatasetStatus;
use App\Enums\DatasetVersionStatus;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Services\Datasets\DatasetVersions;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['yak.sandbox.incus_pool' => 'tank']);
    Process::fake();
    $this->dataset = Dataset::factory()->create(['volume_name' => 'yak-data-demo']);
    $this->versions = app(DatasetVersions::class);
});

function fetchedVersion(Dataset $dataset, string $name): DatasetVersion
{
    return DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => $name]);
}

function assertSnapshotDeletes(int $times): void
{
    Process::assertRanTimes(fn ($process) => in_array('snapshot', $process->command, true) && in_array('delete', $process->command, true), $times);
}

it('publishes a version as current and ready', function () {
    $this->dataset->update(['last_error' => 'old', 'status' => DatasetStatus::Failed]);
    $v1 = fetchedVersion($this->dataset, 'v1');

    $this->versions->publish($v1);

    Process::assertRan(fn ($p) => implode(' ', $p->command) === 'incus storage volume snapshot create tank yak-data-demo v1');
    expect($v1->fresh()->status)->toBe(DatasetVersionStatus::Ready)
        ->and($v1->fresh()->fetched_at)->not->toBeNull()
        ->and($this->dataset->fresh()->current_version_id)->toBe($v1->id)
        ->and($this->dataset->fresh()->status)->toBe(DatasetStatus::Ready)
        ->and($this->dataset->fresh()->last_error)->toBeNull();
});

it('keeps a superseded version while it has a live clone and retires it on release', function () {
    $v1 = fetchedVersion($this->dataset, 'v1');
    $this->versions->publish($v1);
    $clone = DatasetClone::factory()->create(['dataset_version_id' => $v1->id]);

    $v2 = fetchedVersion($this->dataset, 'v2');
    $this->versions->publish($v2);

    expect($v1->fresh()->status)->toBe(DatasetVersionStatus::Ready);
    assertSnapshotDeletes(0);

    $clone->update(['destroyed_at' => now()]);
    $this->versions->releaseClone($clone->fresh());

    Process::assertRan(fn ($p) => implode(' ', $p->command) === 'incus storage volume snapshot delete tank yak-data-demo v1');
    expect($v1->fresh()->status)->toBe(DatasetVersionStatus::Retired)
        ->and($v1->fresh()->retired_at)->not->toBeNull();

    $this->versions->releaseClone($clone->fresh());
    assertSnapshotDeletes(1);
});

it('retires a superseded version without clones at publish', function () {
    $this->versions->publish(fetchedVersion($this->dataset, 'v1'));
    $this->versions->publish(fetchedVersion($this->dataset, 'v2'));

    expect($this->dataset->versions()->where('version', 'v1')->first()->status)->toBe(DatasetVersionStatus::Retired);
    assertSnapshotDeletes(1);
});

it('never deletes the current version snapshot on release', function () {
    $v1 = fetchedVersion($this->dataset, 'v1');
    $this->versions->publish($v1);
    $clone = DatasetClone::factory()->create(['dataset_version_id' => $v1->id, 'destroyed_at' => now()]);

    $this->versions->releaseClone($clone);

    assertSnapshotDeletes(0);
    expect($v1->fresh()->status)->toBe(DatasetVersionStatus::Ready);
});

it('blocks a fetch while two ready versions have live clones', function () {
    $v1 = fetchedVersion($this->dataset, 'v1');
    $this->versions->publish($v1);
    DatasetClone::factory()->create(['dataset_version_id' => $v1->id]);
    $v2 = fetchedVersion($this->dataset, 'v2');
    $this->versions->publish($v2);

    expect($this->versions->canStartFetch($this->dataset))->toBeTrue();

    DatasetClone::factory()->create(['dataset_version_id' => $v2->id]);

    expect($this->versions->canStartFetch($this->dataset))->toBeFalse();
});
