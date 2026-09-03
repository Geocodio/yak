<?php

use App\Models\BranchDeployment;
use App\Models\User;
use App\Services\DeploymentShareTokens;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('mint creates a share link and exposes the URL once via flash', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    $this->post(route('deployments.share.store', $deployment), ['expires_in_days' => 7])
        ->assertRedirect(route('deployments.show', $deployment));

    expect($deployment->fresh()->public_share_token_hash)->not->toBeNull();

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('shareLink.active', true));
});

test('mint exposes the minted URL once via the mintedUrl prop', function () {
    $deployment = BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    $this->post(route('deployments.share.store', $deployment), ['expires_in_days' => 7]);

    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('mintedUrl', fn ($v) => is_string($v) && str_starts_with($v, 'https://foo.yak.example.com/_share/')));

    // The minted URL is a one-time flash -- a second visit must not see it again.
    $this->get(route('deployments.show', $deployment))
        ->assertInertia(fn (Assert $page) => $page->where('mintedUrl', null));
});

test('revoke removes the share token', function () {
    $deployment = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($deployment->fresh(), expiresInDays: 7);

    $this->delete(route('deployments.share.destroy', $deployment))
        ->assertRedirect(route('deployments.show', $deployment))
        ->assertSessionHas('success', 'Share link revoked.');

    expect($deployment->fresh()->public_share_token_hash)->toBeNull();
});
