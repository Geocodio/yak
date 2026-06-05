<?php

use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Livewire\Deployments\DeploymentShow;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(fn () => Bus::fake());

it('renders deployment details', function () {
    $d = BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->assertSee('foo.yak.example.com')
        ->assertSee($d->status->value);
});

it('dispatches a rebuild job from the rebuild button', function () {
    $d = BranchDeployment::factory()->running()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->call('rebuild')
        ->assertSuccessful();

    Bus::assertDispatched(RebuildDeploymentJob::class, fn ($job) => $job->deploymentId === $d->id);
});

it('dispatches a destroy job from the destroy button', function () {
    $d = BranchDeployment::factory()->running()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->call('destroy')
        ->assertSuccessful();

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $d->id);
});

it('shows recent logs', function () {
    $d = BranchDeployment::factory()->running()->create();
    $d->logs()->create(['level' => 'info', 'message' => 'container started']);

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->assertSee('container started');
});

it('renders recent deployment logs with phase and message', function () {
    $user = User::factory()->create();
    $deployment = BranchDeployment::factory()->running()->create();

    DeploymentLog::record($deployment, 'info', 'lifecycle', 'Deployment ready');
    DeploymentLog::record($deployment, 'info', 'refresh', "\$ composer install\nNothing to install");
    DeploymentLog::record($deployment, 'error', 'fetch', 'git fetch failed');

    Livewire::actingAs($user)
        ->test(DeploymentShow::class, ['deployment' => $deployment])
        ->assertSee('Deployment ready')
        ->assertSee('composer install')
        ->assertSee('git fetch failed')
        ->assertSee('refresh')
        ->assertSee('fetch');
});

it('marks a deployment long-lived via the toggle', function () {
    $d = BranchDeployment::factory()->running()->create(['long_lived' => false]);

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->set('longLived', true)
        ->assertSuccessful();

    expect($d->fresh()->long_lived)->toBeTrue();
});

it('resets the custom TTL when long-lived is turned off', function () {
    $d = BranchDeployment::factory()->running()->longLived(720)->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->set('longLived', false)
        ->assertSuccessful();

    $fresh = $d->fresh();
    expect($fresh->long_lived)->toBeFalse();
    expect($fresh->idle_timeout_minutes)->toBeNull();
});

it('saves a custom hibernation timeout from shorthand', function () {
    $d = BranchDeployment::factory()->running()->longLived()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->set('idleTimeoutInput', '2w')
        ->call('saveIdleTimeout')
        ->assertHasNoErrors();

    expect($d->fresh()->idle_timeout_minutes)->toBe(20160);
});

it('rejects an invalid duration', function () {
    $d = BranchDeployment::factory()->running()->longLived()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->set('idleTimeoutInput', 'nonsense')
        ->call('saveIdleTimeout')
        ->assertHasErrors('idleTimeoutInput');

    expect($d->fresh()->idle_timeout_minutes)->toBeNull();
});

it('shows the long-lived indicator on the page', function () {
    $d = BranchDeployment::factory()->running()->longLived()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentShow::class, ['deployment' => $d])
        ->assertSee('Long-lived');
});
