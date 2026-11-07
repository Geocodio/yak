<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;
use App\Services\DeploymentGitHubSync;

it('fires the observer when a rebuild fails', function () {
    $repository = Repository::factory()->create(['current_template_version' => 2]);
    $deployment = BranchDeployment::factory()->for($repository)->running()->create([
        'template_version' => 1,
        'current_commit_sha' => 'sha',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();
    $manager->shouldReceive('createFromTemplate')->once()->andThrow(new RuntimeException('boom'));

    $sync = Mockery::mock(DeploymentGitHubSync::class);
    $this->app->instance(DeploymentGitHubSync::class, $sync);
    $sync->shouldReceive('sync')->with(Mockery::any(), DeploymentStatus::Destroying)->once();
    $sync->shouldReceive('sync')->with(Mockery::any(), DeploymentStatus::Starting)->once();
    $sync->shouldReceive('sync')->with(Mockery::any(), DeploymentStatus::Failed)->once();

    expect(fn () => (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class)))
        ->toThrow(RuntimeException::class, 'boom');

    expect($deployment->fresh()->failure_reason)->toBe('rebuild: boom');
});
