<?php

use App\Exceptions\DeploymentStartTimeoutException;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('clones from the pinned template snapshot', function () {
    Process::fake();

    $repo = Repository::factory()->create([
        'slug' => 'example-repo',
        'current_template_version' => 5,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
        'template_version' => 5,
    ]);

    app(DeploymentContainerManager::class)->createFromTemplate($deployment);

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus copy yak-tpl-example-repo/ready-v5 deploy-42')
    );
});

it('uses the deployment template_version (not repo current)', function () {
    Process::fake();

    $repo = Repository::factory()->create([
        'slug' => 'example-repo',
        'current_template_version' => 9,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
        'template_version' => 3,
    ]);

    app(DeploymentContainerManager::class)->createFromTemplate($deployment);

    Process::assertRan(fn ($process) => str_contains($process->command, 'yak-tpl-example-repo/ready-v3')
    );
});

it('starts the container, runs cold_start, polls health probe, and returns the ip', function () {
    Process::fake([
        'incus start deploy-42' => Process::result(exitCode: 0),
        'incus exec deploy-42 *' => Process::result(exitCode: 0, output: ''),
        'incus list deploy-42 *' => Process::result(exitCode: 0, output: '10.0.0.42'),
    ]);

    Http::fake([
        'http://10.0.0.42*' => Http::response('ok', 200),
    ]);

    $repo = Repository::factory()->create([
        'slug' => 'example-repo',
        'preview_manifest' => [
            'port' => 80,
            'health_probe_path' => '/up',
            'cold_start' => 'docker compose up -d',
            'health_probe_timeout_seconds' => 5,
            'cold_start_timeout_seconds' => 5,
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
        'template_version' => 1,
    ]);

    $ip = app(DeploymentContainerManager::class)->start($deployment);

    expect($ip)->toBe('10.0.0.42');
    Process::assertRan(fn ($process) => str_contains($process->command, 'incus start deploy-42'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker compose up -d'));
});

it('raises when the health probe never goes green', function () {
    Process::fake([
        'incus start *' => Process::result(exitCode: 0),
        'incus exec *' => Process::result(exitCode: 0, output: ''),
        'incus list *' => Process::result(exitCode: 0, output: '10.0.0.42'),
    ]);

    Http::fake([
        'http://10.0.0.42*' => Http::response('no', 500),
    ]);

    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'health_probe_path' => '/up',
            'health_probe_timeout_seconds' => 1,
            'cold_start_timeout_seconds' => 1,
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create(['container_name' => 'deploy-x']);

    app(DeploymentContainerManager::class)->start($deployment);
})->throws(DeploymentStartTimeoutException::class);

it('runs git fetch + checkout and the manifest refresh', function () {
    Process::fake([
        'incus exec * -- test -f /app/.yak/preview.sh' => Process::result(exitCode: 1),
        'incus exec *' => Process::result(exitCode: 0),
    ]);

    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'checkout_refresh' => 'docker compose restart web',
            'checkout_refresh_timeout_seconds' => 10,
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
        'dirty' => true,
    ]);

    app(DeploymentContainerManager::class)->applyCheckoutRefresh($deployment, 'abcdef1234567890');

    Process::assertRan(fn ($p) => str_contains($p->command, 'git fetch --all --prune'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'git checkout --force abcdef1234567890'));
    Process::assertRan(fn ($p) => str_contains($p->command, 'docker compose restart web'));

    $deployment->refresh();
    expect($deployment->current_commit_sha)->toBe('abcdef1234567890');
    expect($deployment->dirty)->toBeFalse();
});

it('prefers .yak/preview.sh when present in the repo', function () {
    Process::fake([
        'incus exec * -- test -f /app/.yak/preview.sh' => Process::result(exitCode: 0),
        'incus exec *' => Process::result(exitCode: 0),
    ]);

    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'checkout_refresh' => 'FALLBACK_SHOULD_NOT_RUN',
            'checkout_refresh_timeout_seconds' => 10,
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create([
        'container_name' => 'deploy-42',
    ]);

    app(DeploymentContainerManager::class)->applyCheckoutRefresh($deployment, 'sha1234');

    Process::assertRan(fn ($p) => str_contains($p->command, '/app/.yak/preview.sh sha1234'));
    Process::assertDidntRun(fn ($p) => str_contains($p->command, 'FALLBACK_SHOULD_NOT_RUN'));
});

it('stops the container via incus stop', function () {
    Process::fake();

    $deployment = BranchDeployment::factory()->running()->create(['container_name' => 'deploy-42']);

    app(DeploymentContainerManager::class)->stop($deployment);

    Process::assertRan(fn ($p) => str_contains($p->command, 'incus stop deploy-42'));
});

it('destroys the container via incus delete --force', function () {
    Process::fake();

    $deployment = BranchDeployment::factory()->running()->create(['container_name' => 'deploy-42']);

    app(DeploymentContainerManager::class)->destroy($deployment);

    Process::assertRan(fn ($p) => str_contains($p->command, 'incus delete --force deploy-42'));
});

it('is idempotent on destroy when the container is already gone', function () {
    Process::fake([
        'incus delete --force *' => Process::result(exitCode: 1, errorOutput: 'Error: Not Found'),
    ]);

    $deployment = BranchDeployment::factory()->running()->create(['container_name' => 'deploy-gone']);

    app(DeploymentContainerManager::class)->destroy($deployment);
    Process::assertRan(fn ($p) => str_contains($p->command, 'incus delete --force deploy-gone'));
});
