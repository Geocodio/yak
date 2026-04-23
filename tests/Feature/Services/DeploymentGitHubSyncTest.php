<?php

use App\Channels\GitHub\AppService;
use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Services\DeploymentGitHubSync;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 42);
});

it('creates a GH deployment on first Starting sync', function () {
    $deployment = BranchDeployment::factory()
        ->state(['status' => DeploymentStatus::Starting])
        ->create(['github_deployment_id' => null, 'current_commit_sha' => 'abc', 'hostname' => 'x.yak.example.com']);

    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldReceive('createDeployment')->once()->andReturn(987);
    $github->shouldReceive('createDeploymentStatus')->once()
        ->with(42, Mockery::any(), 987, 'in_progress', Mockery::any(), Mockery::any(), Mockery::any());

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Starting);

    expect($deployment->fresh()->github_deployment_id)->toBe(987);
});

it('writes a success status when transitioning to Running', function () {
    $deployment = BranchDeployment::factory()->running()->create([
        'github_deployment_id' => 987,
        'hostname' => 'x.yak.example.com',
    ]);

    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldNotReceive('createDeployment');
    $github->shouldReceive('createDeploymentStatus')->once()
        ->with(42, Mockery::any(), 987, 'success', 'https://x.yak.example.com', Mockery::any(), Mockery::any());

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Running);
});

it('writes inactive when hibernated', function () {
    $deployment = BranchDeployment::factory()->hibernated()->create(['github_deployment_id' => 987]);

    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldReceive('createDeploymentStatus')->once()
        ->with(42, Mockery::any(), 987, 'inactive', Mockery::any(), Mockery::any(), Mockery::any());

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Hibernated);
});

it('marks inactive then deletes when destroyed', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create(['github_deployment_id' => 987]);

    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldReceive('createDeploymentStatus')->once()
        ->with(42, Mockery::any(), 987, 'inactive', Mockery::any(), Mockery::any(), Mockery::any());
    $github->shouldReceive('deleteDeployment')->once()->with(42, Mockery::any(), 987);

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Destroyed);
});

it('writes failure state for Failed', function () {
    $deployment = BranchDeployment::factory()->failed('boom')->create(['github_deployment_id' => 987]);

    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldReceive('createDeploymentStatus')->once()
        ->with(42, Mockery::any(), 987, 'failure', Mockery::any(), Mockery::any(), 'boom');

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Failed);
});

it('is a no-op when github_deployment_id is null on non-starting states', function () {
    $deployment = BranchDeployment::factory()->destroyed()->create(['github_deployment_id' => null]);

    $github = Mockery::mock(AppService::class);
    $this->app->instance(AppService::class, $github);
    $github->shouldNotReceive('createDeploymentStatus');
    $github->shouldNotReceive('deleteDeployment');

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Destroyed);
});
