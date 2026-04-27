<?php

use App\Exceptions\DeploymentStartTimeoutException;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;
use Illuminate\Http\Client\ConnectionException;
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
        'incus list deploy-42 *' => Process::result(exitCode: 0, output: <<<'CSV'
            deploy-42,"172.17.0.1 (docker0)
            10.0.0.42 (eth0)"
            CSV),
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

it('keeps polling the health probe when the connection is refused before the app binds', function () {
    Process::fake([
        'incus start *' => Process::result(exitCode: 0),
        'incus exec *' => Process::result(exitCode: 0, output: ''),
        'incus list *' => Process::result(exitCode: 0, output: 'deploy-42,10.0.0.42 (eth0)'),
    ]);

    $callCount = 0;
    Http::fake(function () use (&$callCount) {
        $callCount++;
        if ($callCount < 3) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response('ok', 200);
    });

    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'port' => 80,
            'health_probe_path' => '/',
            'cold_start' => '',
            'health_probe_timeout_seconds' => 5,
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create(['container_name' => 'deploy-42']);

    $ip = app(DeploymentContainerManager::class)->start($deployment);

    expect($ip)->toBe('10.0.0.42');
    expect($callCount)->toBeGreaterThanOrEqual(3);
});

it('raises when the health probe never goes green', function () {
    Process::fake([
        'incus start *' => Process::result(exitCode: 0),
        'incus exec *' => Process::result(exitCode: 0, output: ''),
        'incus list *' => Process::result(exitCode: 0, output: 'deploy-x,10.0.0.42 (eth0)'),
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
        'incus exec * -- test -f /workspace/.yak/preview.sh' => Process::result(exitCode: 1),
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
        'incus exec * -- test -f /workspace/.yak/preview.sh' => Process::result(exitCode: 0),
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

    Process::assertRan(fn ($p) => str_contains($p->command, '/workspace/.yak/preview.sh sha1234'));
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

it('is idempotent on start when the container is already running', function () {
    Process::fake([
        'incus start *' => Process::result(exitCode: 1, errorOutput: 'Error: The instance is already running'),
        'incus exec *' => Process::result(exitCode: 0, output: ''),
        'incus list *' => Process::result(exitCode: 0, output: 'deploy-42,10.0.0.42 (eth0)'),
    ]);
    Http::fake(['http://10.0.0.42*' => Http::response('ok', 200)]);

    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'port' => 80,
            'health_probe_path' => '/',
            'cold_start' => '',
            'health_probe_timeout_seconds' => 5,
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->create(['container_name' => 'deploy-42']);

    $ip = app(DeploymentContainerManager::class)->start($deployment);

    expect($ip)->toBe('10.0.0.42');
});

it('writes a refresh log entry with captured output and exit code', function () {
    Process::fake([
        'incus exec deploy-log -- bash -lc *' => Process::result(
            exitCode: 0,
            output: "fetched refs\nchecked out\n",
        ),
    ]);

    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'checkout_refresh' => 'cd /workspace && composer install',
        ],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'container_name' => 'deploy-log',
        'current_commit_sha' => 'old-sha',
    ]);

    app(DeploymentContainerManager::class)->applyCheckoutRefresh($deployment, 'new-sha');

    $logs = $deployment->logs()->orderBy('id')->get();

    // fetch + checkout + refresh = 3 entries minimum
    expect($logs)->toHaveCount(3);
    expect($logs[0]->phase)->toBe('fetch');
    expect($logs[1]->phase)->toBe('checkout');
    expect($logs[2]->phase)->toBe('refresh');
    expect($logs[2]->level)->toBe('info');
    expect($logs[2]->message)->toContain('fetched refs');
    expect($logs[2]->metadata['exit_code'])->toBe(0);
});

it('writes an error-level log when a command fails, then throws', function () {
    Process::fake([
        'incus exec deploy-fail -- bash -lc *' => Process::result(
            exitCode: 1,
            output: 'some stdout',
            errorOutput: 'boom on fetch',
        ),
    ]);

    $repo = Repository::factory()->create([
        'preview_manifest' => ['checkout_refresh' => 'composer install'],
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create([
        'container_name' => 'deploy-fail',
    ]);

    expect(fn () => app(DeploymentContainerManager::class)
        ->applyCheckoutRefresh($deployment, 'new-sha')
    )->toThrow(RuntimeException::class);

    $log = $deployment->logs()->latest('id')->first();
    expect($log->level)->toBe('error');
    expect($log->phase)->toBe('fetch');
    expect($log->message)->toContain('boom on fetch');
    expect($log->metadata['exit_code'])->toBe(1);
});
