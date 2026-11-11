<?php

use App\Enums\DatasetVersionStatus;
use App\Enums\RestoreStepStatus;
use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\BranchDeployment;
use App\Models\Dataset;
use App\Models\DatasetClone;
use App\Models\DatasetVersion;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Datasets\DatasetClones;
use App\Services\DeploymentContainerManager;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\SandboxRestorer;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    Sleep::fake();
    config(['yak.sandbox.incus_pool' => 'tank']);
    $this->repository = Repository::factory()->create(['slug' => 'acme/app']);
});

function withReadyDataset(Repository $repository): Dataset
{
    $dataset = Dataset::factory()->create(['repository_id' => $repository->id, 'volume_name' => 'yak-data-acme-app', 'mount_path' => '/data']);
    $version = DatasetVersion::factory()->create(['dataset_id' => $dataset->id, 'version' => 'v1', 'status' => DatasetVersionStatus::Ready]);
    $dataset->update(['current_version_id' => $version->id]);

    return $dataset;
}

function datasetCommandCount(string $needle): int
{
    $count = 0;
    Process::assertRan(function ($process) use (&$count, $needle) {
        $count += str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, $needle) ? 1 : 0;

        return true;
    });

    return $count;
}

function destroyedRestorableThread(): Thread
{
    return Thread::factory()->create([
        'repo' => 'acme/app',
        'branch' => 'yak/thread-branch',
        'sandbox_state' => ThreadSandboxState::Destroyed,
        'runner_state' => RunnerState::None,
    ]);
}

it('clones the dataset into a restored thread sandbox', function () {
    withReadyDataset($this->repository);
    Process::fake();
    $thread = destroyedRestorableThread();

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue();

    expect(datasetCommandCount("storage volume copy tank/yak-data-acme-app/v1 tank/yak-clone-thread-{$thread->id}"))->toBe(1)
        ->and(datasetCommandCount("source=yak-clone-thread-{$thread->id} path=/data"))->toBe(1)
        ->and(DatasetClone::count())->toBe(1);
});

it('runs no incus volume command for an undeclared repository', function () {
    Process::fake();

    expect(app(SandboxRestorer::class)->restore(destroyedRestorableThread()))->toBeTrue();

    Process::assertNothingRan();
});

it('still restores when the clone fails', function () {
    withReadyDataset($this->repository);
    Process::fake(['*' => Process::result(errorOutput: 'pool full', exitCode: 1)]);
    $thread = destroyedRestorableThread();

    expect(app(SandboxRestorer::class)->restore($thread))->toBeTrue()
        ->and(DatasetClone::count())->toBe(0)
        ->and($thread->restoreSteps()->get()->every(fn ($step) => $step->status !== RestoreStepStatus::Failed))->toBeTrue();
});

it('removes the clone when a thread sandbox is destroyed and revives it on restore', function () {
    $directory = sys_get_temp_dir() . '/yak-dataset-' . uniqid();
    mkdir($directory, 0777, true);
    $git = LocalGitSandboxManager::withRepository($directory);
    app()->instance(SandboxManager::class, $git);
    withReadyDataset($this->repository);
    Process::fake();
    $thread = Thread::factory()->create([
        'repo' => 'acme/app',
        'sandbox_container' => 'yak-thread-77',
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::None,
        'branch' => $git->branch,
    ]);
    $first = app(DatasetClones::class)->attach($thread, $this->repository, 'yak-thread-77');

    app(ThreadSandbox::class)->destroy($thread);
    $thread->refresh();
    app(ThreadSandbox::class)->destroy($thread);

    expect($first->fresh()->destroyed_at)->not->toBeNull()
        ->and(datasetCommandCount("storage volume delete tank yak-clone-thread-{$thread->id}"))->toBe(1);

    app(SandboxRestorer::class)->restore($thread->fresh());

    expect(DatasetClone::count())->toBe(1)
        ->and($first->fresh()->destroyed_at)->toBeNull();

    File::deleteDirectory($directory);
});

it('attaches a clone to a preview and deletes it after the container', function () {
    withReadyDataset($this->repository);
    Process::fake();
    $deployment = BranchDeployment::factory()->create(['repository_id' => $this->repository->id, 'container_name' => 'deploy-5']);
    $manager = app(DeploymentContainerManager::class);

    $manager->createFromTemplate($deployment);
    expect(datasetCommandCount("source=yak-clone-preview-{$deployment->id} path=/data"))->toBe(1);

    $manager->destroy($deployment);
    $manager->destroy($deployment);

    $commands = [];
    Process::assertRan(function ($process) use (&$commands) {
        $commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return true;
    });
    $containerDelete = array_search('incus delete --force deploy-5', $commands, true);
    $volumeDelete = array_search("incus storage volume delete tank yak-clone-preview-{$deployment->id}", $commands, true);

    expect($containerDelete)->not->toBeFalse()
        ->and($volumeDelete)->toBeGreaterThan($containerDelete)
        ->and(datasetCommandCount('storage volume delete'))->toBe(1);
});

it('recreates a preview clone on the same row', function () {
    withReadyDataset($this->repository);
    Process::fake();
    $deployment = BranchDeployment::factory()->create(['repository_id' => $this->repository->id, 'container_name' => 'deploy-6']);
    $manager = app(DeploymentContainerManager::class);

    $manager->createFromTemplate($deployment);
    $manager->destroy($deployment);
    $manager->createFromTemplate($deployment);

    expect(DatasetClone::count())->toBe(1)
        ->and(DatasetClone::first()->destroyed_at)->toBeNull();
});
