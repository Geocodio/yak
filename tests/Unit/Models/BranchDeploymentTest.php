<?php

use App\Models\BranchDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('uses the global idle_minutes for a standard deployment', function () {
    config()->set('yak.deployments.idle_minutes', 15);

    $deployment = BranchDeployment::factory()->make([
        'long_lived' => false,
        'idle_timeout_minutes' => null,
    ]);

    expect($deployment->effectiveIdleMinutes())->toBe(15);
});

it('uses the long_lived default when flagged with no override', function () {
    config()->set('yak.deployments.long_lived_idle_minutes', 4320);

    $deployment = BranchDeployment::factory()->make([
        'long_lived' => true,
        'idle_timeout_minutes' => null,
    ]);

    expect($deployment->effectiveIdleMinutes())->toBe(4320);
});

it('prefers an explicit idle_timeout_minutes override', function () {
    $deployment = BranchDeployment::factory()->make([
        'long_lived' => true,
        'idle_timeout_minutes' => 720,
    ]);

    expect($deployment->effectiveIdleMinutes())->toBe(720);
});

it('exposes a longLived factory state', function () {
    $deployment = BranchDeployment::factory()->longLived(720)->create();

    expect($deployment->long_lived)->toBeTrue();
    expect($deployment->idle_timeout_minutes)->toBe(720);
});
