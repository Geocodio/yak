<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\User;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
});

it('returns state=ready for a running deployment', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    $this->actingAs(User::factory()->create())
        ->getJson('/internal/deployments/status?host=foo.yak.example.com')
        ->assertOk()
        ->assertJson(['state' => 'ready']);
});

it('returns state=pending for a starting deployment', function () {
    $d = BranchDeployment::factory()->pending()->create(['hostname' => 'foo.yak.example.com']);
    $d->status = DeploymentStatus::Starting;
    $d->save();

    $this->actingAs(User::factory()->create())
        ->getJson('/internal/deployments/status?host=foo.yak.example.com')
        ->assertOk()
        ->assertJson(['state' => 'pending']);
});

it('returns state=failed for a failed deployment', function () {
    BranchDeployment::factory()->failed('boom')->create(['hostname' => 'foo.yak.example.com']);

    $this->actingAs(User::factory()->create())
        ->getJson('/internal/deployments/status?host=foo.yak.example.com')
        ->assertOk()
        ->assertJson(['state' => 'failed', 'reason' => 'boom']);
});

it('returns 404 for an unknown host', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/internal/deployments/status?host=no-such.yak.example.com')
        ->assertStatus(404);
});
