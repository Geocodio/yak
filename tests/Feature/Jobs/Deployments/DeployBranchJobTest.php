<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DeployBranchJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;

it('creates the container, starts it, checks out the sha, and marks running', function () {
    $deployment = BranchDeployment::factory()->create([
        'current_commit_sha' => 'abc123',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);

    $manager->shouldReceive('createFromTemplate')->once()->with(Mockery::on(fn ($d) => $d->id === $deployment->id));
    $manager->shouldReceive('start')->once()->andReturn('10.0.0.10');
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'abc123');

    (new DeployBranchJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('marks failed and records the reason on exception', function () {
    $deployment = BranchDeployment::factory()->create(['current_commit_sha' => 'abc']);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('createFromTemplate')->andThrow(new RuntimeException('clone failed'));

    expect(fn () => (new DeployBranchJob($deployment->id))->handle(app(DeploymentContainerManager::class)))
        ->toThrow(RuntimeException::class);

    $fresh = $deployment->fresh();
    expect($fresh->status)->toBe(DeploymentStatus::Failed);
    expect($fresh->failure_reason)->toContain('clone failed');
});

it('writes lifecycle logs across the happy path', function () {
    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Pending,
        'current_commit_sha' => 'abc123',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('createFromTemplate')->once();
    $manager->shouldReceive('start')->once()->andReturn('10.0.0.5');
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'abc123');

    (new DeployBranchJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    $phases = $deployment->logs()->orderBy('id')->pluck('phase')->all();
    expect($phases)->toContain('lifecycle');

    $last = $deployment->logs()->latest('id')->first();
    expect($last->message)->toContain('Deployment ready');
});

it('writes an error lifecycle log on failure', function () {
    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Pending,
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('createFromTemplate')->andThrow(new RuntimeException('template missing'));

    try {
        (new DeployBranchJob($deployment->id))->handle(app(DeploymentContainerManager::class));
    } catch (Throwable) {
        // expected — job rethrows
    }

    $errorLog = $deployment->logs()
        ->where('level', 'error')
        ->where('phase', 'lifecycle')
        ->latest('id')
        ->first();
    expect($errorLog)->not->toBeNull();
    expect($errorLog->message)->toContain('template missing');
});
