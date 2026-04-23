<?php

use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => Bus::fake());

it('dispatches RebuildDeploymentJob for each active deployment', function () {
    $repo = Repository::factory()->create();
    BranchDeployment::factory()->for($repo)->count(3)->running()->create();
    BranchDeployment::factory()->for($repo)->destroyed()->create(); // should be skipped

    (new RebuildRepositoryDeploymentsJob($repo->id))->handle();

    Bus::assertDispatched(RebuildDeploymentJob::class, 3);
});

it('skips deployments from other repos', function () {
    $repo = Repository::factory()->create();
    $otherRepo = Repository::factory()->create();
    BranchDeployment::factory()->for($repo)->running()->create();
    BranchDeployment::factory()->for($otherRepo)->running()->create();

    (new RebuildRepositoryDeploymentsJob($repo->id))->handle();

    Bus::assertDispatched(RebuildDeploymentJob::class, 1);
});
