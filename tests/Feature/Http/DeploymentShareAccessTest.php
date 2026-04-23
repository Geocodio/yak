<?php

use App\Models\BranchDeployment;
use App\Models\User;
use App\Services\DeploymentShareTokens;
use App\Services\DeploymentWaker;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
});

it('redirects + sets share_session cookie for the first share-link hit', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'share.yak.example.com']);
    $token = app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);

    $response = $this->withHeaders([
        'X-Forwarded-Host' => 'share.yak.example.com',
        'X-Forwarded-Uri' => "/_share/{$token}/dashboard",
    ])->get('/internal/deployments/wake');

    $response->assertStatus(302);
    $response->assertHeader('Location', '/dashboard');
    $cookies = $response->headers->getCookies();
    $names = array_map(fn ($c) => $c->getName(), $cookies);
    expect($names)->toContain('yak_share_session');
});

it('accepts a subsequent request with the cookie (no share prefix)', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'share.yak.example.com']);
    app(DeploymentShareTokens::class)->mint($deployment->fresh(), expiresInDays: 7);
    $deployment->refresh();

    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn(['state' => 'ready', 'host' => '10.0.0.1', 'port' => 80]);

    $cookieValue = app(DeploymentShareTokens::class)->cookieValue($deployment);

    $this->withHeaders([
        'X-Forwarded-Host' => 'share.yak.example.com',
        'X-Forwarded-Uri' => '/users',
    ])
        ->withCookie('yak_share_session', $cookieValue)
        ->get('/internal/deployments/wake')
        ->assertOk()
        ->assertHeader('X-Upstream-Host', '10.0.0.1');
});

it('rejects an anonymous request with an invalid share token', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'share.yak.example.com']);

    $this->withHeaders([
        'X-Forwarded-Host' => 'share.yak.example.com',
        'X-Forwarded-Uri' => '/_share/bogus/dashboard',
    ])->get('/internal/deployments/wake')
        ->assertStatus(401);
});

it('redirects anonymous without share prefix and without cookie to auth-bounce', function () {
    BranchDeployment::factory()->running()->create(['hostname' => 'share.yak.example.com']);

    $response = $this->withHeaders([
        'X-Forwarded-Host' => 'share.yak.example.com',
        'X-Forwarded-Uri' => '/dashboard',
    ])->get('/internal/deployments/wake');

    $response->assertRedirectContains('/deployments/auth-bounce');
    $response->assertRedirectContains('signature=');
});

it('still allows authenticated OAuth users (backwards compatible)', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'share.yak.example.com']);

    $waker = Mockery::mock(DeploymentWaker::class);
    $this->app->instance(DeploymentWaker::class, $waker);
    $waker->shouldReceive('ensureReady')->andReturn(['state' => 'ready', 'host' => '10.0.0.1', 'port' => 80]);

    $this->actingAs(User::factory()->create())
        ->withHeaders([
            'X-Forwarded-Host' => 'share.yak.example.com',
            'X-Forwarded-Uri' => '/',
        ])->get('/internal/deployments/wake')
        ->assertOk();
});
