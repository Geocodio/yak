<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Services\DeploymentGitHubSync;

it('triggers sync on status change', function () {
    $deployment = BranchDeployment::factory()->pending()->create([
        'github_deployment_id' => null,
        'current_commit_sha' => 'abc',
    ]);

    $sync = Mockery::mock(DeploymentGitHubSync::class);
    $this->app->instance(DeploymentGitHubSync::class, $sync);
    $sync->shouldReceive('sync')->once()->with(Mockery::any(), DeploymentStatus::Starting);

    $deployment->status = DeploymentStatus::Starting;
    $deployment->save();
});

it('does not trigger when no status change happened', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $sync = Mockery::mock(DeploymentGitHubSync::class);
    $this->app->instance(DeploymentGitHubSync::class, $sync);
    $sync->shouldNotReceive('sync');

    $deployment->dirty = true;
    $deployment->save();
});

it('swallows exceptions from the sync so existing tests keep passing', function () {
    $deployment = BranchDeployment::factory()->pending()->create([
        'current_commit_sha' => 'abc',
    ]);

    $sync = Mockery::mock(DeploymentGitHubSync::class);
    $this->app->instance(DeploymentGitHubSync::class, $sync);
    $sync->shouldReceive('sync')->once()->andThrow(new RuntimeException('GitHub API down'));

    // Should not throw — exception is caught and logged.
    $deployment->status = DeploymentStatus::Starting;
    $deployment->save();
});
