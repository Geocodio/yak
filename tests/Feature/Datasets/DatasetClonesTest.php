<?php

use App\Enums\DatasetVersionStatus;
use App\Models\BranchDeployment;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Datasets\DatasetClones;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['yak.sandbox.incus_pool' => 'tank']);
    Process::fake();
    $this->repository = Repository::factory()->create(['slug' => 'demo']);
    $this->dataset = Dataset::factory()->create([
        'repository_id' => $this->repository->id,
        'volume_name' => 'yak-data-demo',
        'mount_path' => '/data',
    ]);
    $this->v1 = DatasetVersion::factory()->create(['dataset_id' => $this->dataset->id, 'version' => 'v1', 'status' => DatasetVersionStatus::Ready]);
    $this->dataset->update(['current_version_id' => $this->v1->id]);
    $this->thread = Thread::factory()->create(['repo' => 'demo']);
    $this->clones = app(DatasetClones::class);
});

function ranCommands(): array
{
    $commands = [];
    Process::assertRan(function ($process) use (&$commands) {
        $commands[] = implode(' ', $process->command);

        return true;
    });

    return $commands;
}

function countRan(string $needle): int
{
    $count = 0;
    Process::assertRan(function ($process) use (&$count, $needle) {
        if (str_contains(implode(' ', $process->command), $needle)) {
            $count++;
        }

        return true;
    });

    return $count;
}

it('clones the current version and attaches it writable', function () {
    $clone = $this->clones->attach($this->thread, $this->repository, 'yak-thread-1');

    $commands = ranCommands();
    $id = $this->thread->id;
    expect($commands)->toBe([
        "incus storage volume copy tank/yak-data-demo/v1 tank/yak-clone-thread-{$id}",
        "incus config device add yak-thread-1 data disk pool=tank source=yak-clone-thread-{$id} path=/data",
    ])
        ->and($clone->dataset_version_id)->toBe($this->v1->id)
        ->and($clone->destroyed_at)->toBeNull();
});

it('does nothing for a repository without a dataset', function () {
    $other = Repository::factory()->create(['slug' => 'other']);

    expect($this->clones->attach(Thread::factory()->create(['repo' => 'other']), $other, 'c'))->toBeNull();

    Process::assertNothingRan();
});

it('does nothing while the dataset has no current version', function () {
    $this->dataset->update(['current_version_id' => null]);

    expect($this->clones->attach($this->thread, $this->repository, 'c'))->toBeNull();

    Process::assertNothingRan();
});

it('gives an extra repository no dataset', function () {
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'main-app', 'role' => 'primary']);

    expect($this->clones->attach($this->thread, $this->repository, 'c'))->toBeNull();

    Process::assertNothingRan();
});

it('removes the clone volume and leaves no row when the device add fails', function () {
    Process::fake([
        '*' => fn ($process) => str_contains(implode(' ', $process->command), 'device add')
            ? Process::result(errorOutput: 'busy', exitCode: 1)
            : Process::result(),
    ]);

    expect($this->clones->attach($this->thread, $this->repository, 'c'))->toBeNull()
        ->and(DatasetClone::count())->toBe(0);

    expect(countRan('storage volume delete tank yak-clone-thread-' . $this->thread->id))->toBe(1);
});

it('returns the live row without a second clone', function () {
    $first = $this->clones->attach($this->thread, $this->repository, 'c');
    $second = $this->clones->attach($this->thread, $this->repository, 'c');

    expect($second->id)->toBe($first->id)
        ->and(countRan('storage volume copy'))->toBe(1);
});

it('destroys a clone once and tolerates repeats', function () {
    $this->clones->attach($this->thread, $this->repository, 'c');

    $this->clones->destroyFor($this->thread);
    $this->clones->destroyFor($this->thread);

    expect(countRan('storage volume delete'))->toBe(1)
        ->and(DatasetClone::first()->destroyed_at)->not->toBeNull();
});

it('is a no-op to destroy for an owner that never had a clone', function () {
    $this->clones->destroyFor($this->thread);

    Process::assertNothingRan();
});

it('revives the same row when a thread is restored after destroy', function () {
    $first = $this->clones->attach($this->thread, $this->repository, 'c');
    $this->clones->destroyFor($this->thread);

    $revived = $this->clones->attach($this->thread, $this->repository, 'c2');

    expect($revived->id)->toBe($first->id)
        ->and($revived->destroyed_at)->toBeNull()
        ->and($revived->container_name)->toBe('c2')
        ->and(DatasetClone::count())->toBe(1)
        ->and(countRan('storage volume copy'))->toBe(2)
        ->and(countRan('device add c2 data disk'))->toBe(1);
});

it('revives the same row when a preview is recreated', function () {
    $deployment = BranchDeployment::factory()->create(['repository_id' => $this->repository->id]);

    $first = $this->clones->attach($deployment, $this->repository, 'p');
    $this->clones->destroyFor($deployment);
    $revived = $this->clones->attach($deployment, $this->repository, 'p');

    expect($revived->id)->toBe($first->id)
        ->and($revived->destroyed_at)->toBeNull()
        ->and(DatasetClone::count())->toBe(1);
});

it('deletes the old version snapshot when its last clone goes', function () {
    $this->clones->attach($this->thread, $this->repository, 'c');
    $v2 = DatasetVersion::factory()->create(['dataset_id' => $this->dataset->id, 'version' => 'v2', 'status' => DatasetVersionStatus::Ready]);
    $this->dataset->update(['current_version_id' => $v2->id]);

    $this->clones->destroyFor($this->thread);

    expect(countRan('snapshot delete tank yak-data-demo v1'))->toBe(1)
        ->and($this->v1->fresh()->status)->toBe(DatasetVersionStatus::Retired);
});
