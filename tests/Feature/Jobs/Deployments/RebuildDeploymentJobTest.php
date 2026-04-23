<?php

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;

it('destroys the container, re-pins to latest template, and re-creates', function () {
    $repo = Repository::factory()->create(['current_template_version' => 9]);
    $deployment = BranchDeployment::factory()
        ->for($repo)
        ->running()
        ->create(['template_version' => 3, 'current_commit_sha' => 'sha']);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();
    $manager->shouldReceive('createFromTemplate')->once()
        ->with(Mockery::on(fn ($d) => $d->template_version === 9));
    $manager->shouldReceive('start')->once()->andReturn('10.0.0.5');
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'sha');

    (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    $fresh = $deployment->fresh();
    expect($fresh->template_version)->toBe(9);
    expect($fresh->status)->toBe(DeploymentStatus::Running);
});

it('records failure and re-throws on exception', function () {
    $repo = Repository::factory()->create(['current_template_version' => 5]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'template_version' => 3, 'current_commit_sha' => 'sha',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->andThrow(new RuntimeException('destroy failed'));

    expect(fn () => (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class)))
        ->toThrow(RuntimeException::class);

    $fresh = $deployment->fresh();
    expect($fresh->status)->toBe(DeploymentStatus::Failed);
    expect($fresh->failure_reason)->toContain('destroy failed');
});
