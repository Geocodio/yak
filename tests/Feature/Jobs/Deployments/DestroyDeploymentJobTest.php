<?php

use App\Channels\GitHub\AppService;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use App\Services\DeploymentGitHubSync;

it('destroys the container and transitions to Destroyed', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();

    (new DestroyDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class), app(DeploymentGitHubSync::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Destroyed);
});

it('is a no-op on an already-destroyed deployment', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('destroy');

    (new DestroyDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class), app(DeploymentGitHubSync::class));
});

it('force-destroys from Starting (state-machine guard bypassed)', function () {
    // Starting → Destroying isn't a permitted transition, so a save()-based
    // flip would throw. The watchdog relies on the raw-update bypass.
    $deployment = BranchDeployment::factory()->starting()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();

    (new DestroyDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class), app(DeploymentGitHubSync::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Destroyed);
});

it('marks the GitHub deployment inactive and deletes it, which the raw status updates would skip', function () {
    config()->set('yak.channels.github.installation_id', 42);
    $deployment = BranchDeployment::factory()->failed('boom')->create(['github_deployment_id' => 987]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $manager->shouldReceive('destroy')->once();
    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldReceive('createDeploymentStatus')->once()
        ->with(42, Mockery::any(), 987, 'inactive', Mockery::any(), Mockery::any(), Mockery::any());
    $github->shouldReceive('deleteDeployment')->once()->with(42, Mockery::any(), 987);

    (new DestroyDeploymentJob($deployment->id))->handle($manager, app(DeploymentGitHubSync::class));

    expect($deployment->fresh()->github_deployment_id)->toBeNull();
});
