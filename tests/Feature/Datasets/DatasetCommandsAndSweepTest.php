<?php

use App\Enums\DatasetVersionStatus;
use App\Exceptions\DatasetFetchRefused;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Services\Datasets\DatasetFetcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['yak.sandbox.incus_pool' => 'tank']);
    $this->repository = Repository::factory()->create(['slug' => 'demo']);
});

it('fetches inline for a slug and queues nothing', function () {
    Queue::fake();
    $version = DatasetVersion::factory()->create(['status' => DatasetVersionStatus::Ready, 'version' => 'v1']);
    $fetcher = Mockery::mock(DatasetFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->withArgs(fn ($repository, $requested) => $repository->is($this->repository) && $requested === 'v1')->andReturn($version);
    app()->instance(DatasetFetcher::class, $fetcher);

    $this->artisan('yak:dataset-fetch demo --dataset-version=v1')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(class_exists('App\\Jobs\\FetchDatasetJob'))->toBeFalse();
});

it('fails for an unknown slug', function () {
    $this->artisan('yak:dataset-fetch nope')->assertExitCode(1);
});

it('refuses to overlap with a running fetch', function () {
    $fetcher = Mockery::mock(DatasetFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    app()->instance(DatasetFetcher::class, $fetcher);
    $lock = Cache::lock('yak:dataset-fetch:' . $this->repository->id, 60);
    $lock->get();

    $this->artisan('yak:dataset-fetch demo')->expectsOutputToContain('already running')->assertExitCode(1);

    $lock->release();
});

it('prints a refusal, exits non-zero and releases the lock', function () {
    $fetcher = Mockery::mock(DatasetFetcher::class);
    $fetcher->shouldReceive('fetch')->andThrow(new DatasetFetchRefused('pool is full'));
    app()->instance(DatasetFetcher::class, $fetcher);

    $this->artisan('yak:dataset-fetch demo')->expectsOutputToContain('pool is full')->assertExitCode(1);

    $lock = Cache::lock('yak:dataset-fetch:' . $this->repository->id, 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('sweeps orphaned clones and stranded snapshots, and is a no-op otherwise', function () {
    $dataset = Dataset::factory()->create(['repository_id' => $this->repository->id, 'volume_name' => 'yak-data-demo']);
    $v1 = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => 'v1', 'status' => DatasetVersionStatus::Ready]);
    $v2 = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => 'v2', 'status' => DatasetVersionStatus::Ready]);
    $dataset->update(['current_version_id' => $v2->id]);
    $orphan = DatasetClone::factory()->create(['dataset_version_id' => $v2->id, 'container_name' => 'gone', 'volume_name' => 'yak-clone-thread-9']);
    Process::fake(fn ($process) => in_array('info', $process->command, true) ? Process::result(exitCode: 1) : Process::result());

    $this->artisan('yak:dataset-sweep')->expectsOutputToContain('Reclaimed 2')->assertExitCode(0);

    expect($orphan->fresh()->destroyed_at)->not->toBeNull()
        ->and($v1->fresh()->status)->toBe(DatasetVersionStatus::Retired);

    $this->artisan('yak:dataset-sweep')->expectsOutputToContain('Reclaimed 0')->assertExitCode(0);
});

it('schedules the sweep hourly and never schedules a fetch', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command . '|' . $event->expression);

    expect($commands->contains(fn ($c) => str_contains($c, 'yak:dataset-sweep') && str_ends_with($c, '|0 * * * *')))->toBeTrue()
        ->and($commands->contains(fn ($c) => str_contains($c, 'yak:dataset-fetch')))->toBeFalse();
});
