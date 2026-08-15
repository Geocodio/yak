<?php

use App\Actions\BackfillOpenPrDeployments;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Jobs\Deployments\BackfillOpenPrDeploymentsJob;
use App\Jobs\Deployments\DeployBranchJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    config()->set('yak.channels.github.installation_id', 12345);
});

it('backfills only deployment-eligible repositories', function () {
    $eligible = Repository::factory()->create([
        'slug' => 'acme/eligible',
        'deployments_enabled' => true,
        'setup_status' => 'ready',
        'current_template_version' => 1,
    ]);
    // Ineligible: deployments disabled.
    Repository::factory()->create([
        'slug' => 'acme/disabled',
        'deployments_enabled' => false,
        'setup_status' => 'ready',
        'current_template_version' => 1,
    ]);
    // Ineligible: setup not finished (no versioned template yet).
    Repository::factory()->create([
        'slug' => 'acme/setting-up',
        'deployments_enabled' => true,
        'setup_status' => 'pending',
        'current_template_version' => 0,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')
        ->once()
        ->with(12345, 'acme/eligible')
        ->andReturn([
            ['number' => 1, 'head' => ['ref' => 'feature-x', 'sha' => 'abc']],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new BackfillOpenPrDeploymentsJob)->handle(app(BackfillOpenPrDeployments::class));

    expect(BranchDeployment::where('repository_id', $eligible->id)->where('branch_name', 'feature-x')->exists())->toBeTrue();
    Bus::assertDispatched(DeployBranchJob::class, 1);
});

it('continues the sweep when one repository fails', function () {
    $failing = Repository::factory()->create([
        'slug' => 'acme/failing',
        'deployments_enabled' => true,
        'setup_status' => 'ready',
        'current_template_version' => 1,
    ]);
    $healthy = Repository::factory()->create([
        'slug' => 'acme/healthy',
        'deployments_enabled' => true,
        'setup_status' => 'ready',
        'current_template_version' => 1,
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->with(12345, 'acme/failing')->andThrow(new RuntimeException('GitHub 500'));
    $github->shouldReceive('listOpenPullRequests')->with(12345, 'acme/healthy')->andReturn([
        ['number' => 9, 'head' => ['ref' => 'still-works', 'sha' => 'def']],
    ]);
    app()->instance(GitHubAppService::class, $github);

    (new BackfillOpenPrDeploymentsJob)->handle(app(BackfillOpenPrDeployments::class));

    expect(BranchDeployment::where('repository_id', $healthy->id)->where('branch_name', 'still-works')->exists())->toBeTrue()
        ->and(BranchDeployment::where('repository_id', $failing->id)->exists())->toBeFalse();
});
