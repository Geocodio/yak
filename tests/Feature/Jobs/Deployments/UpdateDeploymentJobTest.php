<?php

use App\Jobs\Deployments\UpdateDeploymentJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;

it('applies checkout refresh when the deployment is running', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'new-sha');

    (new UpdateDeploymentJob($deployment->id, 'new-sha'))
        ->handle(app(DeploymentContainerManager::class));
});

it('marks dirty when the deployment is hibernated', function () {
    $deployment = BranchDeployment::factory()->hibernated()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('applyCheckoutRefresh');

    (new UpdateDeploymentJob($deployment->id, 'new-sha'))
        ->handle(app(DeploymentContainerManager::class));

    $fresh = $deployment->fresh();
    expect($fresh->dirty)->toBeTrue();
    expect($fresh->current_commit_sha)->toBe('new-sha');
});

it('is a no-op on a failed or destroyed deployment', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('applyCheckoutRefresh');

    (new UpdateDeploymentJob($deployment->id, 'new-sha'))
        ->handle(app(DeploymentContainerManager::class));
});
