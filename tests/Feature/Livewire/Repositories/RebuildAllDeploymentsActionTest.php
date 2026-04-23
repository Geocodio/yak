<?php

use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Livewire\Repositories\RebuildAllDeploymentsAction;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('dispatches the bulk rebuild job and flashes a status', function () {
    Bus::fake();
    $repo = Repository::factory()->create();

    Livewire::test(RebuildAllDeploymentsAction::class, ['repository' => $repo])
        ->call('rebuildAll');

    Bus::assertDispatched(
        RebuildRepositoryDeploymentsJob::class,
        fn (RebuildRepositoryDeploymentsJob $job): bool => $job->repositoryId === $repo->id,
    );
});
