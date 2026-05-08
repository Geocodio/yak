<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\WakeHibernatedDeploymentJob;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use App\Services\DeploymentWaker;
use Illuminate\Support\Facades\Queue;

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

it('dispatches a wake job and returns pending for a hibernated deployment', function () {
    Queue::fake();

    $deployment = BranchDeployment::factory()->hibernated()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('start');

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('pending');
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Starting);
    Queue::assertPushed(WakeHibernatedDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

it('does not block the HTTP request on the slow start path', function () {
    Queue::fake();

    $deployment = BranchDeployment::factory()->hibernated()->create();

    // If the waker tried to call into the container manager synchronously
    // (the old behaviour) this mock would record it. The whole point of
    // the refactor is to push that work into a job.
    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('start');
    $manager->shouldNotReceive('applyCheckoutRefresh');

    app(DeploymentWaker::class)->ensureReady($deployment);
});

it('returns pending without re-dispatching when wake is already in flight', function () {
    Queue::fake();

    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Starting,
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('pending');
    Queue::assertNothingPushed();
});

it('returns failed for a deployment in the Failed state', function () {
    $deployment = BranchDeployment::factory()->create([
        'status' => DeploymentStatus::Failed,
        'failure_reason' => 'something blew up',
    ]);

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('failed');
    expect($result['reason'])->toContain('something blew up');
});

it('returns failed for a destroyed deployment', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create();

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldNotReceive('start');

    $result = app(DeploymentWaker::class)->ensureReady($deployment);

    expect($result['state'])->toBe('failed');
});

it('writes a lifecycle log when waking a hibernated deployment', function () {
    Queue::fake();

    $deployment = BranchDeployment::factory()->hibernated()->create([
        'current_commit_sha' => 'abc',
        'dirty' => true,
    ]);

    app(DeploymentWaker::class)->ensureReady($deployment);

    $log = $deployment->logs()->where('phase', 'lifecycle')->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->message)->toContain('Waking');
});
