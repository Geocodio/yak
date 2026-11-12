<?php

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Datasets\DatasetAvailability;
use App\Services\Datasets\DatasetAvailabilityState;
use App\Services\Threads\EnvironmentNote;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'demo']);
    $this->thread = Thread::factory()->create(['repo' => 'demo']);
});

it('reports not declared without config or row', function () {
    fakeYakFiles(['config.yml' => "version: 1\n"]);

    $availability = DatasetAvailability::forRepository($this->repository);

    expect($availability->state)->toBe(DatasetAvailabilityState::NotDeclared)
        ->and($availability->message())->toBeNull();
});

it('reports not on this server when declared but never fetched', function () {
    fakeYakFiles(['config.yml' => "version: 1\ndata:\n  name: \"demo\"\n  prefix: \"data/demo\"\n  version_key: \"data/demo/CURRENT\"\n  mount_path: \"/data\"\n"]);

    $availability = DatasetAvailability::forThread($this->thread);

    expect($availability->state)->toBe(DatasetAvailabilityState::NotOnThisServer)
        ->and($availability->message())->toBe('Dataset not on this server yet. Work that needs it stays on your computer.')
        ->and($availability->toArray()['state'])->toBe('not_on_this_server');
});

it('reports fetching and failed rows without a current version', function (DatasetStatus $status, DatasetAvailabilityState $expected) {
    Dataset::factory()->create(['repository_id' => $this->repository->id, 'status' => $status]);

    expect(DatasetAvailability::forRepository($this->repository)->state)->toBe($expected);
})->with([
    'fetching' => [DatasetStatus::Fetching, DatasetAvailabilityState::Fetching],
    'failed' => [DatasetStatus::Failed, DatasetAvailabilityState::Failed],
    'absent' => [DatasetStatus::Absent, DatasetAvailabilityState::NotOnThisServer],
]);

it('reports ready, and unattached for a thread without a live clone', function () {
    $dataset = Dataset::factory()->create(['repository_id' => $this->repository->id]);
    $version = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => 'v1']);
    $dataset->update(['current_version_id' => $version->id]);

    expect(DatasetAvailability::forRepository($this->repository)->state)->toBe(DatasetAvailabilityState::Ready)
        ->and(DatasetAvailability::forThread($this->thread)->state)->toBe(DatasetAvailabilityState::Unattached)
        ->and(DatasetAvailability::forThread($this->thread)->message())->toBe('Dataset not attached to this sandbox.');

    DatasetClone::factory()->create(['dataset_version_id' => $version->id, 'owner_type' => Thread::class, 'owner_id' => $this->thread->id]);

    expect(DatasetAvailability::forThread($this->thread)->state)->toBe(DatasetAvailabilityState::Ready);
});

it('looks only at the primary repository of a thread', function () {
    Dataset::factory()->create(['repository_id' => $this->repository->id, 'status' => DatasetStatus::Fetching]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'main-app', 'role' => 'primary']);

    expect(DatasetAvailability::forThread($this->thread)->state)->toBe(DatasetAvailabilityState::NotDeclared);
});

it('adds a Dataset section to the note only when the dataset is missing', function () {
    $note = app(EnvironmentNote::class);
    $missing = new DatasetAvailability(DatasetAvailabilityState::NotOnThisServer, 'demo', null);

    expect($note->sectionsForRestore($this->thread, [], false, null, $missing)['Dataset'])->toBe($missing->message())
        ->and($note->sectionsForRestore($this->thread, [], false, null, new DatasetAvailability(DatasetAvailabilityState::Ready, 'demo', 'v1')))->not->toHaveKey('Dataset')
        ->and($note->sectionsForRestore($this->thread, [], false, null, new DatasetAvailability(DatasetAvailabilityState::NotDeclared, null, null)))->not->toHaveKey('Dataset')
        ->and($note->sectionsForRestore($this->thread, [], false, null))->not->toHaveKey('Dataset');
});
