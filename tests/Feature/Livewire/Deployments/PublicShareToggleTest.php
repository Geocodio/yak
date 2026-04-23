<?php

use App\Livewire\Deployments\PublicShareToggle;
use App\Models\BranchDeployment;
use App\Models\User;
use App\Services\DeploymentShareTokens;
use Livewire\Livewire;

it('mints a token and exposes the URL once', function () {
    $d = BranchDeployment::factory()->running()->create(['hostname' => 'foo.yak.example.com']);

    $component = Livewire::actingAs(User::factory()->create())
        ->test(PublicShareToggle::class, ['deployment' => $d])
        ->set('expiresInDays', 7)
        ->call('mint');

    $component->assertSet('generatedUrl', fn ($v) => is_string($v) && str_starts_with($v, 'https://foo.yak.example.com/_share/'));

    $component->call('clearShownToken');
    $component->assertSet('generatedUrl', null);

    expect($d->fresh()->public_share_token_hash)->not->toBeNull();
});

it('revokes the token', function () {
    $d = BranchDeployment::factory()->running()->create();
    app(DeploymentShareTokens::class)->mint($d->fresh(), expiresInDays: 7);

    Livewire::actingAs(User::factory()->create())
        ->test(PublicShareToggle::class, ['deployment' => $d])
        ->call('revoke');

    expect($d->fresh()->public_share_token_hash)->toBeNull();
});
