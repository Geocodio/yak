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

it('does not hibernate a long-lived deployment within its longer TTL', function () {
    config()->set('yak.deployments.idle_minutes', 15);
    config()->set('yak.deployments.long_lived_idle_minutes', 4320); // 3 days

    $longLived = BranchDeployment::factory()->running()->longLived()->create([
        'last_accessed_at' => now()->subMinutes(30), // past 15m, well within 3d
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('stop');

    (new HibernateIdleDeploymentsJob)->handle(app(DeploymentContainerManager::class));

    expect($longLived->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('hibernates a long-lived deployment past its custom TTL', function () {
    $longLived = BranchDeployment::factory()->running()->longLived(720)->create([
        'last_accessed_at' => now()->subMinutes(800), // past the 720m override
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('stop')->once()->with(Mockery::on(fn ($d) => $d->id === $longLived->id));

    (new HibernateIdleDeploymentsJob)->handle(app(DeploymentContainerManager::class));

    expect($longLived->fresh()->status)->toBe(DeploymentStatus::Hibernated);
});
