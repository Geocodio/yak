<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use App\Services\DeploymentWaker;

it('returns ready with upstream for a running deployment', function () {
    $deployment = BranchDeployment::factory()->running()->create([
        'hostname' => 'x.yak.example.com',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);

    $manager->shouldNotReceive('start');
    $manager->shouldReceive('resolveContainerIp')
        ->with($deployment->container_name)->andReturn('10.0.0.5');

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('ready');
    expect($result['host'])->toBe('10.0.0.5');
});

it('starts a hibernated deployment and returns ready on fast boot', function () {
    $deployment = BranchDeployment::factory()->hibernated()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->once()->with(Mockery::on(fn ($d) => $d->id === $deployment->id))->andReturn('10.0.0.9');
    $manager->shouldNotReceive('applyCheckoutRefresh');

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('ready');
    expect($result['host'])->toBe('10.0.0.9');
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('applies a pending dirty refresh before serving traffic', function () {
    $deployment = BranchDeployment::factory()->hibernated()->create([
        'dirty' => true,
        'current_commit_sha' => 'old-sha',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->with(Mockery::on(fn ($d) => $d->id === $deployment->id))->andReturn('10.0.0.9');
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'old-sha');

    app(DeploymentWaker::class)->ensureReady($deployment);
});

it('returns pending when wake takes longer than the budget', function () {
    config()->set('yak.deployments.wake_request_budget_seconds', 0);

    $deployment = BranchDeployment::factory()->hibernated()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->andReturn('10.0.0.9');

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    // With a 0s budget, even a fast start should return pending because
    // any time elapsed > 0 exceeds the budget.
    expect($result['state'])->toBe('pending');
});

it('marks failed and returns failed on a start exception', function () {
    $deployment = BranchDeployment::factory()->hibernated()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('start')->andThrow(new RuntimeException('boom'));

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('failed');
    expect($result['reason'])->toContain('boom');
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
    expect($deployment->fresh()->failure_reason)->toContain('boom');
});

it('returns failed for a destroyed deployment', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('start');

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('failed');
});
