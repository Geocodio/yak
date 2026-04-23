<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Repository;
use ArtisanBuild\FatEnums\StateMachine\InvalidStateTransition;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

it('creates a deployment via factory with defaults', function () {
    $deployment = BranchDeployment::factory()->create();

    expect($deployment->status)->toBe(DeploymentStatus::Pending);
    expect($deployment->dirty)->toBeFalse();
    expect($deployment->template_version)->toBeGreaterThanOrEqual(0);
});

it('belongs to a repository', function () {
    $repo = Repository::factory()->create();
    $deployment = BranchDeployment::factory()->for($repo)->create();

    expect($deployment->repository->is($repo))->toBeTrue();
});

it('casts status to the DeploymentStatus enum', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    expect($deployment->status)->toBe(DeploymentStatus::Running);
});

it('blocks invalid status transitions', function () {
    $deployment = BranchDeployment::factory()->pending()->create();

    $deployment->status = DeploymentStatus::Running;
    $deployment->save();
})->throws(InvalidStateTransition::class);

it('allows a valid status transition', function () {
    $deployment = BranchDeployment::factory()->pending()->create();

    $deployment->status = DeploymentStatus::Starting;
    $deployment->save();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Starting);
});

it('enforces unique hostname', function () {
    $repo = Repository::factory()->create();
    BranchDeployment::factory()->for($repo)->create(['hostname' => 'a-b.yak.example.com']);

    BranchDeployment::factory()->for($repo)->create([
        'branch_name' => 'different',
        'hostname' => 'a-b.yak.example.com',
    ]);
})->throws(QueryException::class);

it('enforces unique (repository_id, branch_name)', function () {
    $repo = Repository::factory()->create();
    BranchDeployment::factory()->for($repo)->create(['branch_name' => 'feat/x']);

    BranchDeployment::factory()->for($repo)->create([
        'branch_name' => 'feat/x',
        'hostname' => 'other-host.yak.example.com',
    ]);
})->throws(QueryException::class);

it('casts last_accessed_at, public_share_expires_at, and dirty', function () {
    $deployment = BranchDeployment::factory()->create([
        'last_accessed_at' => now(),
        'public_share_expires_at' => now()->addDays(7),
        'dirty' => true,
    ]);

    expect($deployment->last_accessed_at)->toBeInstanceOf(CarbonInterface::class);
    expect($deployment->public_share_expires_at)->toBeInstanceOf(CarbonInterface::class);
    expect($deployment->dirty)->toBeTrue();
});
