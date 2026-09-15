<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;
use Illuminate\Http\Client\ConnectionException;
use Mockery\MockInterface;

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

it('writes lifecycle logs across the rebuild path', function () {
    $repo = Repository::factory()->create([
        'current_template_version' => 3,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'template_version' => 1,
        'current_commit_sha' => 'abc',
    ]);

    $manager = Mockery::mock(DeploymentContainerManager::class);
    $this->app->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();
    $manager->shouldReceive('createFromTemplate')->once();
    $manager->shouldReceive('start')->once()->andReturn('10.0.0.8');
    $manager->shouldReceive('applyCheckoutRefresh')->once();

    (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    $phases = $deployment->logs()->where('phase', 'lifecycle')->orderBy('id')->pluck('message')->all();
    expect(implode("\n", $phases))
        ->toContain('Destroying container')
        ->toContain('Cloning template snapshot')
        ->toContain('Deployment ready');
});

it('rebuilds from the branch head rather than a stale stored sha', function () {
    $repo = Repository::factory()->create(['github_full_name' => 'acme/widgets']);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'current_commit_sha' => 'stalesha',
        'branch_name' => 'feat/thing',
    ]);

    $github = Mockery::mock(GitHubAppService::class);
    $this->app->instance(GitHubAppService::class, $github);
    $github->shouldReceive('findOpenPullRequestForBranch')
        ->once()
        ->with(Mockery::any(), 'acme/widgets', 'feat/thing')
        ->andReturn(['number' => 7, 'head' => ['sha' => 'freshsha']]);

    $manager = mockRebuildManager();
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'freshsha');

    (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->current_commit_sha)->toBe('freshsha');
});

it('keeps the stored sha when the branch has no open pull request', function () {
    $repo = Repository::factory()->create(['github_full_name' => 'acme/widgets']);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'current_commit_sha' => 'stalesha',
    ]);

    $github = Mockery::mock(GitHubAppService::class);
    $this->app->instance(GitHubAppService::class, $github);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(null);

    $manager = mockRebuildManager();
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'stalesha');

    (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->current_commit_sha)->toBe('stalesha');
});

it('falls back to the stored sha when GitHub is unreachable', function () {
    $repo = Repository::factory()->create(['github_full_name' => 'acme/widgets']);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'current_commit_sha' => 'stalesha',
    ]);

    $github = Mockery::mock(GitHubAppService::class);
    $this->app->instance(GitHubAppService::class, $github);
    $github->shouldReceive('findOpenPullRequestForBranch')
        ->once()
        ->andThrow(new ConnectionException('network down'));

    $manager = mockRebuildManager();
    $manager->shouldReceive('applyCheckoutRefresh')->once()->with(Mockery::any(), 'stalesha');

    (new RebuildDeploymentJob($deployment->id))->handle(app(DeploymentContainerManager::class));

    expect($deployment->fresh()->current_commit_sha)->toBe('stalesha');
});

/**
 * The three lifecycle calls every rebuild makes before the checkout, which
 * these cases share and none of them are asserting on.
 */
function mockRebuildManager(): MockInterface
{
    $manager = Mockery::mock(DeploymentContainerManager::class);
    app()->instance(DeploymentContainerManager::class, $manager);
    $manager->shouldReceive('destroy')->once();
    $manager->shouldReceive('createFromTemplate')->once();
    $manager->shouldReceive('start')->once()->andReturn('10.0.0.9');

    return $manager;
}
