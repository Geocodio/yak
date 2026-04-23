<?php

use App\Models\BranchDeployment;
use App\Services\DeploymentShareTokens;

it('generates a token and stores only its hash', function () {
    $deployment = BranchDeployment::factory()->running()->create([
        'public_share_token_hash' => null,
        'public_share_expires_at' => null,
    ]);

    $token = app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);

    expect($token)->toBeString();
    expect(strlen($token))->toBeGreaterThan(30);

    $fresh = $deployment->fresh();
    expect($fresh->public_share_token_hash)->not->toBeNull();
    expect($fresh->public_share_token_hash)->not->toBe($token);
    expect($fresh->public_share_expires_at)->not->toBeNull();
});

it('caps the lifetime at the configured max_days', function () {
    config()->set('yak.deployments.share.max_days', 14);
    $deployment = BranchDeployment::factory()->running()->create();

    app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 60);

    $expires = $deployment->fresh()->public_share_expires_at;
    expect($expires->diffInDays(now()))->toBeLessThanOrEqual(14);
});

it('verifies a token matching a non-expired hash', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    $token = app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);

    expect(app(DeploymentShareTokens::class)->verify($deployment->fresh(), $token))->toBeTrue();
});

it('rejects a token after expiry', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    $token = app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);
    $deployment->fresh()->update(['public_share_expires_at' => now()->subMinute()]);

    expect(app(DeploymentShareTokens::class)->verify($deployment->fresh(), $token))->toBeFalse();
});

it('rejects a wrong token', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);

    expect(app(DeploymentShareTokens::class)->verify($deployment->fresh(), 'wrong'))->toBeFalse();
});

it('revokes the token', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);

    app(DeploymentShareTokens::class)->revoke($deployment);

    $fresh = $deployment->fresh();
    expect($fresh->public_share_token_hash)->toBeNull();
    expect($fresh->public_share_expires_at)->toBeNull();
});

it('produces deterministic cookie values per deployment', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);

    $service = app(DeploymentShareTokens::class);
    expect($service->cookieValue($deployment->fresh()))
        ->toBe($service->cookieValue($deployment->fresh()));
});

it('verifyCookie accepts the derived value for non-expired deployments', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);
    $deployment->refresh();

    $service = app(DeploymentShareTokens::class);
    $cookie = $service->cookieValue($deployment);

    expect($service->verifyCookie($deployment, $cookie))->toBeTrue();
    expect($service->verifyCookie($deployment, 'wrong-cookie'))->toBeFalse();
});

it('verifyCookie rejects when the token has been revoked', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($deployment, expiresInDays: 7);
    $deployment->refresh();

    $service = app(DeploymentShareTokens::class);
    $cookie = $service->cookieValue($deployment);

    $service->revoke($deployment);
    $deployment->refresh();

    expect($service->verifyCookie($deployment, $cookie))->toBeFalse();
});
