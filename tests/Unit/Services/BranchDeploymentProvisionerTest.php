<?php

use App\Models\Repository;
use App\Services\BranchDeploymentProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a deployment with a natural hostname', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-repo',
        'current_template_version' => 3,
    ]);

    $deployment = app(BranchDeploymentProvisioner::class)->provision($repo, 'feat/tailwind-v4-upgrade');

    expect($deployment->hostname)->toBe('example-repo-feat-tailwind-v4-upgrade.yak.example.com');
    expect($deployment->template_version)->toBe(3);
    expect($deployment->container_name)->toStartWith('deploy-');
});

it('appends a 4-char collision suffix when hostname is taken', function () {
    $repo1 = Repository::factory()->create(['slug' => 'foo']);
    $repo2 = Repository::factory()->create(['slug' => 'foo-bar']);

    app(BranchDeploymentProvisioner::class)->provision($repo1, 'bar-baz');
    // Same resulting hostname: 'foo-bar-baz.yak.example.com' would collide
    $deployment = app(BranchDeploymentProvisioner::class)->provision($repo2, 'baz');

    expect($deployment->hostname)->not->toBe('foo-bar-baz.yak.example.com');
    expect($deployment->hostname)->toStartWith('foo-bar-baz-');
});

it('is idempotent: repeat provision for the same (repo, branch) returns the existing row', function () {
    $repo = Repository::factory()->create(['slug' => 'foo']);

    $first = app(BranchDeploymentProvisioner::class)->provision($repo, 'feat/x');
    $second = app(BranchDeploymentProvisioner::class)->provision($repo, 'feat/x');

    expect($second->id)->toBe($first->id);
});
