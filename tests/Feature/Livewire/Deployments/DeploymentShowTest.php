<?php

use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Livewire\Deployments\DeploymentShow;
use App\Models\BranchDeployment;
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
