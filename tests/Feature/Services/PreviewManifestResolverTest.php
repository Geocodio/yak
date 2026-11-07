<?php

use App\Channels\GitHub\AppService;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\PreviewManifestResolver;

beforeEach(function () {
    config(['yak.channels.github.installation_id' => 1]);

    $this->github = Mockery::mock(AppService::class);
    $this->github->shouldReceive('getBranchHeadSha')->andReturn(null);
    $this->app->instance(AppService::class, $this->github);

    $this->repository = Repository::factory()->create([
        'github_full_name' => 'acme/site',
        'preview_manifest' => ['port' => 8090, 'health_probe_path' => '/up'],
    ]);
});

function previewDeployment(Repository $repository, ?int $pullRequestNumber = 7): BranchDeployment
{
    return BranchDeployment::factory()->for($repository)->running()->create([
        'pr_number' => $pullRequestNumber,
        'current_commit_sha' => 'abc123',
    ]);
}

it('uses the branch file when the pull request changes it', function () {
    $this->github->shouldReceive('listPullRequestFiles')->with(1, 'acme/site', 7)
        ->andReturn([['filename' => '.yak/preview.yml', 'status' => 'modified']]);
    $this->github->shouldReceive('getFileContents')->with(1, 'acme/site', '.yak/preview.yml', 'abc123')
        ->andReturn("port: 9000\nhealth_probe_path: /up\n");

    expect(app(PreviewManifestResolver::class)->for(previewDeployment($this->repository))->port)->toBe(9000);
});

it('uses the default branch manifest when the pull request does not change it', function () {
    $this->github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'README.md', 'status' => 'modified']]);
    $this->github->shouldNotReceive('getFileContents');

    expect(app(PreviewManifestResolver::class)->for(previewDeployment($this->repository))->port)->toBe(8090);
});

it('uses the default branch manifest when the pull request removes the file', function () {
    $this->github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => '.yak/preview.yml', 'status' => 'removed']]);
    $this->github->shouldNotReceive('getFileContents');

    expect(app(PreviewManifestResolver::class)->for(previewDeployment($this->repository))->port)->toBe(8090);
});

it('uses the default branch manifest without a pull request', function () {
    $this->github->shouldNotReceive('listPullRequestFiles');

    expect(app(PreviewManifestResolver::class)->for(previewDeployment($this->repository, null))->port)->toBe(8090);
});

it('falls back when branch file is invalid', function () {
    $this->github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => '.yak/preview.yml', 'status' => 'modified']]);
    $this->github->shouldReceive('getFileContents')->andReturn('port: [');

    expect(app(PreviewManifestResolver::class)->for(previewDeployment($this->repository))->port)->toBe(8090);
});

it('falls back when github errors', function () {
    $this->github->shouldReceive('listPullRequestFiles')->andThrow(new RuntimeException('GitHub returned 500'));

    expect(app(PreviewManifestResolver::class)->for(previewDeployment($this->repository))->port)->toBe(8090);
});

it('does not cache a github error', function () {
    $this->github->shouldReceive('listPullRequestFiles')->once()->andThrow(new RuntimeException('GitHub returned 500'));
    $this->github->shouldReceive('listPullRequestFiles')->once()
        ->andReturn([['filename' => '.yak/preview.yml', 'status' => 'modified']]);
    $this->github->shouldReceive('getFileContents')->andReturn("port: 9000\nhealth_probe_path: /up\n");

    $deployment = previewDeployment($this->repository);
    $resolver = app(PreviewManifestResolver::class);

    expect($resolver->for($deployment)->port)->toBe(8090)
        ->and($resolver->for($deployment)->port)->toBe(9000);
});
