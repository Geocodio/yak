<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;

it('destroys the container and transitions to Destroyed', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();

    (new DestroyDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Destroyed);
});

it('is a no-op on an already-destroyed deployment', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('destroy');

    (new DestroyDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));
});
