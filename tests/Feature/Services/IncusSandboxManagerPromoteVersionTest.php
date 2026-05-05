<?php

use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

it('bumps current_template_version and creates versioned snapshot on promote', function () {
    Process::fake();

    $repository = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'current_template_version' => 2,
    ]);

    $manager = app(IncusSandboxManager::class);
    $ref = $manager->promoteToTemplate('task-sandbox-123', $repository);

    expect($ref)->toBe('yak-tpl-example-org-example-repo/ready-v3');

    $fresh = $repository->fresh();
    expect($fresh->current_template_version)->toBe(3);
    expect($fresh->sandbox_snapshot)->toBe('yak-tpl-example-org-example-repo/ready-v3');

    // templateName() sanitizes slugs (replaces non-alphanumeric with hyphens)
    Process::assertRan(fn ($p) => str_contains($p->command, 'incus snapshot create yak-tpl-example-org-example-repo ready-v3'));
});

it('starts versioning from 1 for repos at version 0', function () {
    Process::fake();
    $repository = Repository::factory()->create([
        'current_template_version' => 0,
    ]);

    $manager = app(IncusSandboxManager::class);
    $ref = $manager->promoteToTemplate('task-sandbox-123', $repository);

    expect($repository->fresh()->current_template_version)->toBe(1);
    expect($ref)->toEndWith('/ready-v1');
});

it('dispatches RebuildRepositoryDeploymentsJob when stale-template clones exist', function () {
    Process::fake();
    Bus::fake();

    $repository = Repository::factory()->create(['current_template_version' => 1]);
    BranchDeployment::factory()->hibernated()->create([
        'repository_id' => $repository->id,
        'template_version' => 1,
    ]);

    app(IncusSandboxManager::class)->promoteToTemplate('task-sandbox-123', $repository);

    Bus::assertDispatched(RebuildRepositoryDeploymentsJob::class, fn ($job) => $job->repositoryId === $repository->id);
});

it('does not dispatch rebuild when no stale-template clones exist', function () {
    Process::fake();
    Bus::fake();

    $repository = Repository::factory()->create(['current_template_version' => 0]);

    app(IncusSandboxManager::class)->promoteToTemplate('task-sandbox-123', $repository);

    Bus::assertNotDispatched(RebuildRepositoryDeploymentsJob::class);
});

it('does not dispatch rebuild for already-destroyed clones', function () {
    Process::fake();
    Bus::fake();

    $repository = Repository::factory()->create(['current_template_version' => 1]);
    BranchDeployment::factory()->destroyed()->create([
        'repository_id' => $repository->id,
        'template_version' => 1,
    ]);

    app(IncusSandboxManager::class)->promoteToTemplate('task-sandbox-123', $repository);

    Bus::assertNotDispatched(RebuildRepositoryDeploymentsJob::class);
});
