<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\HibernateIdleDeploymentsJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;

it('hibernates deployments idle past the config threshold', function () {
    config()->set('yak.deployments.idle_minutes', 15);

    $idle = BranchDeployment::factory()->running()->create([
        'last_accessed_at' => now()->subMinutes(20),
    ]);
    $fresh = BranchDeployment::factory()->running()->create([
        'last_accessed_at' => now()->subMinutes(5),
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('stop')->once()->with(Mockery::on(fn ($d) => $d->id === $idle->id));

    (new HibernateIdleDeploymentsJob)->handle(app(DeploymentContainerManager::class));

    expect($idle->fresh()->status)->toBe(DeploymentStatus::Hibernated);
    expect($fresh->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('ignores non-running deployments', function () {
    BranchDeployment::factory()->hibernated()->create(['last_accessed_at' => now()->subDay()]);
    BranchDeployment::factory()->failed()->create(['last_accessed_at' => now()->subDay()]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('stop');

    (new HibernateIdleDeploymentsJob)->handle(app(DeploymentContainerManager::class));
});
