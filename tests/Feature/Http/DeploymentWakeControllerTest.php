<?php

use App\Models\BranchDeployment;
use App\Models\User;
use App\Services\DeploymentWaker;

beforeEach(function () {
    // Accept any IP as ingress for these HTTP tests. Real middleware is still
    // mounted, we just widen its CIDR to 0.0.0.0/0 for the test context.
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
});

it('returns 401 when there is no auth and no hostname match', function () {
    // Without an authenticated user and without a valid hostname, unauthenticated.
    $this->get('/internal/deployments/wake', [])->assertStatus(401);
});

it('returns 404 for an unknown hostname when authenticated', function () {
    $this->actingAs(User::factory()->create())
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => 'unknown.yak.example.com'])
        ->assertStatus(404);
});

it('returns upstream headers for a running deployment', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn([
        'state' => 'ready', 'host' => '10.0.0.5', 'port' => 8080,
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => 'foo.yak.example.com']);

    $response->assertOk();
    $response->assertHeader('X-Upstream-Host', '10.0.0.5');
    $response->assertHeader('X-Upstream-Port', '8080');
    $response->assertHeader('X-Yak-Deployment-Id', (string) $deployment->id);
});

it('returns 425 + shim HTML when the wake is still pending', function () {
    BranchDeployment::factory()->hibernated()->create(['hostname' => 'foo.yak.example.com']);

    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn(['state' => 'pending']);

    $response = $this->actingAs(User::factory()->create())
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => 'foo.yak.example.com']);

    $response->assertStatus(425);
    $response->assertHeaderMissing('X-Upstream-Host');
    $response->assertSee('Waking preview');
});

it('returns 502 + failed HTML when wake fails', function () {
    BranchDeployment::factory()->hibernated()->create(['hostname' => 'foo.yak.example.com']);

    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn(['state' => 'failed', 'reason' => 'boom']);

    $response = $this->actingAs(User::factory()->create())
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => 'foo.yak.example.com']);

    $response->assertStatus(502);
    $response->assertSee('Preview unavailable');
});

it('updates last_accessed_at on every successful call', function () {
    $deployment = BranchDeployment::factory()->running()->create([
        'hostname' => 'foo.yak.example.com',
        'last_accessed_at' => now()->subHour(),
    ]);

    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn(['state' => 'ready', 'host' => '1.2.3.4', 'port' => 80]);

    $this->actingAs(User::factory()->create())
        ->get('/internal/deployments/wake', ['X-Forwarded-Host' => 'foo.yak.example.com'])
        ->assertOk();

    expect($deployment->fresh()->last_accessed_at->diffInSeconds(now()))->toBeLessThan(5);
});
