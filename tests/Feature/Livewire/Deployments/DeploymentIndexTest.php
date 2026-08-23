<?php

use App\Livewire\Deployments\DeploymentIndex;
use App\Models\BranchDeployment;
use App\Models\User;
use Livewire\Livewire;

it('renders active deployments and hides destroyed ones by default', function () {
    $active = BranchDeployment::factory()->running()->create();
    $destroyed = BranchDeployment::factory()->destroyed()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentIndex::class)
        ->assertSee($active->hostname)
        ->assertDontSee($destroyed->hostname);
});

it('filters by status', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'running-one.yak.example.com']);
    BranchDeployment::factory()->hibernated()->create(['hostname' => 'hib-one.yak.example.com']);

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentIndex::class)
        ->set('statusFilter', 'hibernated')
        ->assertSee('hib-one.yak.example.com')
        ->assertDontSee('running-one.yak.example.com');
});

it('highlights long-lived deployments with a badge and row accent', function () {
    BranchDeployment::factory()->running()->longLived()->create(['branch_name' => 'release/1.0']);

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentIndex::class)
        ->assertSee('Long-lived')
        ->assertSee('bg-sky-50/40', escape: false);
});

it('does not highlight standard deployments', function () {
    BranchDeployment::factory()->running()->create(['branch_name' => 'feat/x', 'long_lived' => false]);

    Livewire::actingAs(User::factory()->create())
        ->test(DeploymentIndex::class)
        ->assertDontSee('Long-lived');
});
