<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\WakeHibernatedDeploymentJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;

it('starts the container and marks running', function () {
    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Starting,
        'dirty' => false,
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->once()->with(Mockery::on(fn ($d) => $d->id === $deployment->id), null)->andReturn('10.0.0.5');

    (new WakeHibernatedDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('applies a pending dirty checkout before serving traffic', function () {
    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Starting,
        'dirty' => true,
        'current_commit_sha' => 'old-sha',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->once()->with(Mockery::any(), 'old-sha')->andReturn('10.0.0.9');

    (new WakeHibernatedDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));
});

it('marks failed and records the reason when start blows up', function () {
    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Starting,
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->andThrow(new RuntimeException('boom'));

    expect(fn () => (new WakeHibernatedDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class)))
        ->toThrow(RuntimeException::class);

    $fresh = $deployment->fresh();
    expect($fresh->status)->toBe(DeploymentStatus::Failed);
    expect($fresh->failure_reason)->toContain('boom');
});

it('is a no-op when the deployment has been destroyed before the worker picks it up', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('start');

    (new WakeHibernatedDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Destroyed);
});
