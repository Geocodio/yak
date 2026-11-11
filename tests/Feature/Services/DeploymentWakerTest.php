<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DeployBranchJob;
use App\Jobs\Deployments\WakeHibernatedDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\User;
use App\Services\DeploymentContainerManager;
use App\Services\DeploymentWaker;
use App\Services\PreviewAccess;
use App\Services\PreviewManifestResolver;
use Illuminate\Support\Facades\Cache;
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

it('serves on the port of the recipe the deployment last ran, without asking GitHub', function () {
    $deployment = BranchDeployment::factory()->running()->create([
        'pr_number' => 92,
        'preview_manifest' => ['port' => 9100, 'health_probe_path' => '/up'],
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $manager->shouldReceive('resolveContainerIp')->andReturn('10.0.0.5');
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $resolver = Mockery::mock(PreviewManifestResolver::class);
    $resolver->shouldNotReceive('for');
    $resolver->shouldNotReceive('manifestFor');
    $this->app->instance(PreviewManifestResolver::class, $resolver);

    expect(app(DeploymentWaker::class)->ensureReady($deployment)['port'])->toBe(9100);
});

it('redeploys a destroyed deployment of an open PR on first hit', function () {
    Queue::fake();
    $deployment = BranchDeployment::factory()->destroyed()->create(['pr_state' => 'open']);
    $deployment->repository->update(['deployments_enabled' => true]);

    expect(app(DeploymentWaker::class)->ensureReady($deployment->fresh()))->toBe(['state' => 'pending']);

    Queue::assertPushed(DeployBranchJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

it('queues one redeploy for two concurrent hits on a destroyed row', function () {
    Queue::fake();
    $deployment = BranchDeployment::factory()->destroyed()->create(['pr_state' => 'open']);
    $deployment->repository->update(['deployments_enabled' => true]);
    $lock = Cache::lock("yak:redeploy:{$deployment->id}", 30);
    $lock->get();

    app(DeploymentWaker::class)->ensureReady($deployment->fresh());
    Queue::assertNothingPushed();

    $lock->release();
    app(DeploymentWaker::class)->ensureReady($deployment->fresh());
    app(DeploymentWaker::class)->ensureReady($deployment->fresh());
    Queue::assertPushed(DeployBranchJob::class, 1);
});

it('does not redeploy a destroyed row of a closed PR or a repo with deployments off', function () {
    Queue::fake();
    $closed = BranchDeployment::factory()->destroyed()->create(['pr_state' => 'closed']);
    $closed->repository->update(['deployments_enabled' => true]);
    $disabled = BranchDeployment::factory()->destroyed()->create(['pr_state' => 'open']);
    $disabled->repository->update(['deployments_enabled' => false]);

    expect(app(DeploymentWaker::class)->ensureReady($closed->fresh())['state'])->toBe('failed')
        ->and(app(DeploymentWaker::class)->ensureReady($disabled->fresh())['state'])->toBe('failed');
    Queue::assertNothingPushed();
});

it('reports pending from the status endpoint only for a redeployable destroyed row', function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    $open = BranchDeployment::factory()->destroyed()->create(['pr_state' => 'open', 'hostname' => 'open.yak.example.com']);
    $open->repository->update(['deployments_enabled' => true]);
    BranchDeployment::factory()->destroyed()->create(['pr_state' => 'closed', 'hostname' => 'closed.yak.example.com']);

    $this->actingAs(User::factory()->create());
    $this->get('/internal/deployments/status?host=open.yak.example.com')->assertJson(['state' => 'pending']);
    $this->get('/internal/deployments/status?host=closed.yak.example.com')->assertJson(['state' => 'failed']);
});

it('answers the wake endpoint with 425 for a redeployable destroyed row', function () {
    Queue::fake();
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    $deployment = BranchDeployment::factory()->destroyed()->create(['pr_state' => 'open', 'hostname' => 'gone.yak.example.com']);
    $deployment->repository->update(['deployments_enabled' => true]);

    $this->withCookie(PreviewAccess::COOKIE_NAME, previewCookie('gone.yak.example.com'))
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => 'gone.yak.example.com', 'X-Forwarded-Uri' => '/'])
        ->assertStatus(425);
    Queue::assertPushed(DeployBranchJob::class);
});
