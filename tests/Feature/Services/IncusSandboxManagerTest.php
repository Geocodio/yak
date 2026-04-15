<?php

use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('yak.sandbox.base_version', 2);
});

it('reports containerExists based on incus info exit code', function () {
    Process::fake([
        'incus info *alive*' => Process::result(exitCode: 0),
        'incus info *dead*' => Process::result(exitCode: 1),
    ]);

    $manager = app(IncusSandboxManager::class);

    expect($manager->containerExists('alive'))->toBeTrue();
    expect($manager->containerExists('dead'))->toBeFalse();
});

it('reclaims a stale container before creating a new one', function () {
    $repo = Repository::factory()->create([
        'slug' => 'demo',
        'sandbox_snapshot' => 'yak-tpl-demo/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'demo']);

    $container = "task-{$task->id}";

    Process::fake([
        "incus info *{$container}*" => Process::result(exitCode: 0),
        "incus delete {$container} --force 2>/dev/null" => Process::result(exitCode: 0),
        'incus snapshot list *' => Process::result(output: 'ready,', exitCode: 0),
        'incus copy *' => Process::result(exitCode: 0),
        'incus config *' => Process::result(exitCode: 0),
        'incus start *' => Process::result(exitCode: 0),
        "incus exec {$container} -- systemctl is-system-running 2>/dev/null" => Process::result(output: 'running', exitCode: 0),
        "incus exec {$container} -- docker info 2>/dev/null" => Process::result(exitCode: 0),
        'incus exec *' => Process::result(exitCode: 0),
        'incus file *' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->create($task, $repo);

    Process::assertRan("incus delete {$container} --force 2>/dev/null");
});

it('skips reclaim when no stale container is present', function () {
    $repo = Repository::factory()->create([
        'slug' => 'clean',
        'sandbox_snapshot' => 'yak-tpl-clean/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'clean']);

    $container = "task-{$task->id}";

    Process::fake([
        "incus info *{$container}*" => Process::result(exitCode: 1),
        'incus snapshot list *' => Process::result(output: 'ready,', exitCode: 0),
        'incus copy *' => Process::result(exitCode: 0),
        'incus config *' => Process::result(exitCode: 0),
        'incus start *' => Process::result(exitCode: 0),
        "incus exec {$container} -- systemctl is-system-running 2>/dev/null" => Process::result(output: 'running', exitCode: 0),
        "incus exec {$container} -- docker info 2>/dev/null" => Process::result(exitCode: 0),
        'incus exec *' => Process::result(exitCode: 0),
        'incus file *' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->create($task, $repo);

    Process::assertNotRan("incus delete {$container} --force 2>/dev/null");
});

it('runs sandbox commands as the yak user by default', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    app(IncusSandboxManager::class)->run('box', 'git status');

    Process::assertRan(fn ($process) => str_contains($process->command, "incus exec 'box' -- sudo -u yak -H bash -c 'git status'"));
});

it('runs sandbox commands as root when asRoot is set', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);

    app(IncusSandboxManager::class)->run('box', 'chown -R yak:yak /home/yak/.claude', asRoot: true);

    Process::assertRan(fn ($process) => str_contains($process->command, "incus exec 'box' -- bash -c 'chown -R yak:yak /home/yak/.claude'")
        && ! str_contains($process->command, 'sudo -u yak'));
});

it('considers a template up to date when stored version matches config', function () {
    $repo = Repository::factory()->create(['sandbox_base_version' => 2]);

    expect(app(IncusSandboxManager::class)->isTemplateUpToDate($repo))->toBeTrue();
});

it('considers a repo up to date when it has no template at all', function () {
    $repo = Repository::factory()->pendingSetup()->create();

    expect(app(IncusSandboxManager::class)->isTemplateUpToDate($repo))->toBeTrue();
});

it('flags legacy templates (null version, snapshot set) as drifted', function () {
    $repo = Repository::factory()->create([
        'sandbox_snapshot' => 'yak-tpl-legacy/ready',
        'sandbox_base_version' => null,
    ]);

    expect(app(IncusSandboxManager::class)->isTemplateUpToDate($repo))->toBeFalse();
});

it('flags a template as outdated when the stored version is behind config', function () {
    $repo = Repository::factory()->create(['sandbox_base_version' => 1]);

    expect(app(IncusSandboxManager::class)->isTemplateUpToDate($repo))->toBeFalse();
});

it('destroys the template and resets repo state when invalidateTemplate is called', function () {
    $repo = Repository::factory()->create([
        'slug' => 'to-invalidate',
        'setup_status' => 'ready',
        'sandbox_snapshot' => 'yak-tpl-to-invalidate/ready',
        'sandbox_base_version' => 1,
    ]);

    Process::fake([
        'incus delete yak-tpl-to-invalidate --force 2>/dev/null' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->invalidateTemplate($repo);

    Process::assertRan('incus delete yak-tpl-to-invalidate --force 2>/dev/null');

    $repo->refresh();
    expect($repo->sandbox_snapshot)->toBeNull();
    expect($repo->sandbox_base_version)->toBeNull();
    expect($repo->setup_status)->toBe('pending');
});

it('stamps the current base_version on the repo when promoting to a template', function () {
    $repo = Repository::factory()->create([
        'slug' => 'promote',
        'sandbox_snapshot' => null,
        'sandbox_base_version' => null,
    ]);
    $task = YakTask::factory()->create(['repo' => 'promote']);

    Process::fake([
        'incus delete yak-tpl-promote --force 2>/dev/null' => Process::result(exitCode: 0),
        'incus stop *' => Process::result(exitCode: 0),
        'incus copy *' => Process::result(exitCode: 0),
        'incus snapshot create *' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $ref = app(IncusSandboxManager::class)->promoteToTemplate("task-{$task->id}", $repo);

    expect($ref)->toBe('yak-tpl-promote/ready');

    $repo->refresh();
    expect($repo->sandbox_base_version)->toBe(2);
});
