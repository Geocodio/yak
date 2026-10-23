<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DeployBranchJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use Illuminate\Support\Facades\Queue;

it('creates the container, starts it, checks out the sha, and marks running', function () {
    $deployment = BranchDeployment::factory()->create([
        'current_commit_sha' => 'abc123',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);

    $manager->shouldReceive('createFromTemplate')->once()->with(Mockery::on(fn ($d) => $d->id === $deployment->id));
    $manager->shouldReceive('start')->once()->with(Mockery::any(), 'abc123')->andReturn('10.0.0.10');

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
    $manager->shouldReceive('start')->once()->with(Mockery::any(), 'abc123')->andReturn('10.0.0.5');

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

it('redeploys a destroyed deployment with a fresh container', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create([
        'current_commit_sha' => 'abc123',
        'failure_reason' => 'old failure',
    ]);
    $deployment->repository->update(['current_template_version' => 4]);
    $oldContainerName = $deployment->container_name;

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('createFromTemplate')->once();
    $manager->shouldReceive('start')->once()->with(Mockery::any(), 'abc123')->andReturn('10.0.0.10');

    (new DeployBranchJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    $fresh = $deployment->fresh();
    expect($fresh->status)->toBe(DeploymentStatus::Running)
        ->and($fresh->container_name)->not->toBe($oldContainerName)
        ->and($fresh->template_version)->toBe(4)
        ->and($fresh->failure_reason)->toBeNull();
});

it('waits for an in-flight teardown before deploying', function () {
    Queue::fake();

    $deployment = BranchDeployment::factory()->create(['status' => DeploymentStatus::Destroying]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('createFromTemplate');

    (new DeployBranchJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Destroying);
    Queue::assertPushed(DeployBranchJob::class, fn (DeployBranchJob $job) => $job->deploymentId === $deployment->id);
});
