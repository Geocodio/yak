<?php

use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Log;
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

it('normalizes /workspace ownership to yak when creating a sandbox', function () {
    $repo = Repository::factory()->create([
        'slug' => 'chown-fix',
        'sandbox_snapshot' => 'yak-tpl-chown-fix/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'chown-fix']);

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

    Process::assertRan(fn ($process) => str_contains($process->command, "incus exec '{$container}' -- bash -c 'chown -R yak:yak '\\''/workspace'\\'''")
        && ! str_contains($process->command, 'sudo -u yak'));
});

it('installs a global gitignore that excludes .yak-artifacts from every sandbox commit', function () {
    $repo = Repository::factory()->create([
        'slug' => 'gitignore-fix',
        'sandbox_snapshot' => 'yak-tpl-gitignore-fix/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'gitignore-fix']);

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

    // The command is double-shell-escaped: the inner payload's single quotes
    // become '\'' once wrapped again by buildExecCommand.
    Process::assertRan(fn ($process) => str_contains($process->command, "mkdir -p '\\''/home/yak/.config/git'\\''")
        && str_contains($process->command, "'\\''.yak-artifacts/'\\''")
        && str_contains($process->command, "> '\\''/home/yak/.config/git/ignore'\\''")
        && str_contains($process->command, 'sudo -u yak'));
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

it('wraps non-root sandbox commands with --preserve-env so passthrough vars survive sudo', function () {
    config()->set('yak.agent_passthrough_env', 'NODE_AUTH_TOKEN,NPM_TOKEN');

    Process::fake([
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->run('task-1', 'echo hello');

    Process::assertRan(function ($p) {
        return str_contains($p->command, "sudo -u yak --preserve-env='NODE_AUTH_TOKEN,NPM_TOKEN' -H bash -c");
    });
});

it('omits --preserve-env when agent_passthrough_env is empty', function () {
    config()->set('yak.agent_passthrough_env', '');

    Process::fake([
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->run('task-1', 'echo hello');

    Process::assertRan(function ($p) {
        return str_contains($p->command, 'sudo -u yak -H bash -c');
    });
});

it('runs root commands without a sudo wrapper so container env pass through untouched', function () {
    config()->set('yak.agent_passthrough_env', 'NODE_AUTH_TOKEN');

    Process::fake([
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->run('task-1', 'chown -R yak:yak /workspace', asRoot: true);

    Process::assertRan(function ($p) {
        return str_contains($p->command, "incus exec 'task-1' -- bash -c")
            && ! str_contains($p->command, 'sudo -u yak');
    });
});

it('forwards agent_passthrough_env vars into the container via incus config set environment.*', function () {
    config()->set('yak.agent_passthrough_env', 'NODE_AUTH_TOKEN,NPM_TOKEN');
    putenv('NODE_AUTH_TOKEN=ghp_test');
    putenv('NPM_TOKEN=npm_secret');

    $repo = Repository::factory()->create([
        'slug' => 'envrepo',
        'sandbox_snapshot' => 'yak-tpl-envrepo/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'envrepo']);

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

    Process::assertRan(fn ($p) => str_contains($p->command, "incus config set '{$container}' 'environment.NODE_AUTH_TOKEN'='ghp_test'"));
    Process::assertRan(fn ($p) => str_contains($p->command, "incus config set '{$container}' 'environment.NPM_TOKEN'='npm_secret'"));

    putenv('NODE_AUTH_TOKEN');
    putenv('NPM_TOKEN');
});

it('skips passthrough env when agent_passthrough_env is empty', function () {
    config()->set('yak.agent_passthrough_env', '');

    $repo = Repository::factory()->create([
        'slug' => 'noenv',
        'sandbox_snapshot' => 'yak-tpl-noenv/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'noenv']);

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

    Process::assertNotRan(fn ($p) => str_contains($p->command, 'environment.'));
});

it('skips passthrough entries whose env var is not defined on the host', function () {
    config()->set('yak.agent_passthrough_env', 'DEFINED_VAR,MISSING_VAR');
    putenv('DEFINED_VAR=hello');
    putenv('MISSING_VAR');  // ensure unset

    $repo = Repository::factory()->create([
        'slug' => 'partial',
        'sandbox_snapshot' => 'yak-tpl-partial/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'partial']);

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

    Process::assertRan(fn ($p) => str_contains($p->command, 'environment.DEFINED_VAR'));
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'environment.MISSING_VAR'));

    putenv('DEFINED_VAR');
});

it('pushes the host yak-browser bundle and makes it executable when creating a sandbox', function () {
    $bundlePath = base_path('sandbox-tools/yak-browser/dist/yak-browser.js');
    @mkdir(dirname($bundlePath), 0755, true);
    file_put_contents($bundlePath, "#!/usr/bin/env node\nconsole.log('test');\n");

    $repo = Repository::factory()->create([
        'slug' => 'browser-push',
        'sandbox_snapshot' => 'yak-tpl-browser-push/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'browser-push']);

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

    Process::assertRan(fn ($p) => str_contains($p->command, 'incus file push')
        && str_contains($p->command, 'yak-browser.js')
        && str_contains($p->command, "{$container}/usr/local/bin/yak-browser"));

    Process::assertRan(fn ($p) => str_contains($p->command, "incus exec '{$container}' -- bash -c 'chmod +x /usr/local/bin/yak-browser'")
        && ! str_contains($p->command, 'sudo -u yak'));
});

it('logs a warning and continues creating the sandbox when the yak-browser bundle is missing', function () {
    $bundlePath = base_path('sandbox-tools/yak-browser/dist/yak-browser.js');
    $backup = null;
    if (file_exists($bundlePath)) {
        $backup = $bundlePath . '.bak-' . uniqid();
        rename($bundlePath, $backup);
    }

    try {
        $repo = Repository::factory()->create([
            'slug' => 'browser-missing',
            'sandbox_snapshot' => 'yak-tpl-browser-missing/ready',
            'sandbox_base_version' => 2,
        ]);
        $task = YakTask::factory()->create(['repo' => 'browser-missing']);

        $container = "task-{$task->id}";

        Log::shouldReceive('channel')->with('yak')->andReturnSelf();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'yak-browser bundle missing'))
            ->atLeast()->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

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

        $result = app(IncusSandboxManager::class)->create($task, $repo);

        expect($result)->toBe($container);

        // No file push for yak-browser should have happened.
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'yak-browser.js'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'chmod +x /usr/local/bin/yak-browser'));
    } finally {
        if ($backup !== null) {
            rename($backup, $bundlePath);
        }
    }
});

it('logs a warning and continues when pushing the yak-browser bundle fails', function () {
    $bundlePath = base_path('sandbox-tools/yak-browser/dist/yak-browser.js');
    @mkdir(dirname($bundlePath), 0755, true);
    if (! file_exists($bundlePath)) {
        file_put_contents($bundlePath, "#!/usr/bin/env node\nconsole.log('test');\n");
    }

    $repo = Repository::factory()->create([
        'slug' => 'browser-pushfail',
        'sandbox_snapshot' => 'yak-tpl-browser-pushfail/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'browser-pushfail']);

    $container = "task-{$task->id}";

    Log::shouldReceive('channel')->with('yak')->andReturnSelf();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('warning')
        ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'yak-browser hot-update failed'))
        ->atLeast()->once();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    Process::fake([
        "incus info *{$container}*" => Process::result(exitCode: 1),
        'incus snapshot list *' => Process::result(output: 'ready,', exitCode: 0),
        'incus copy *' => Process::result(exitCode: 0),
        'incus config *' => Process::result(exitCode: 0),
        'incus start *' => Process::result(exitCode: 0),
        "incus exec {$container} -- systemctl is-system-running 2>/dev/null" => Process::result(output: 'running', exitCode: 0),
        "incus exec {$container} -- docker info 2>/dev/null" => Process::result(exitCode: 0),
        'incus file push *yak-browser.js*' => Process::result(exitCode: 1, errorOutput: 'network blip'),
        'incus exec *' => Process::result(exitCode: 0),
        'incus file *' => Process::result(exitCode: 0),
        '*' => Process::result(exitCode: 0),
    ]);

    $result = app(IncusSandboxManager::class)->create($task, $repo);

    // create() should still return a container name despite the push failing.
    expect($result)->toBe($container);
});

it('pushes the host docker config.json into the sandbox and tightens permissions when present', function () {
    $dockerConfig = tempnam(sys_get_temp_dir(), 'yak-docker-');
    file_put_contents($dockerConfig, '{"auths":{"ghcr.io":{"auth":"dGVzdDp0ZXN0"}}}');
    config()->set('yak.sandbox.docker_config_source', $dockerConfig);

    $repo = Repository::factory()->create([
        'slug' => 'docker-auth',
        'sandbox_snapshot' => 'yak-tpl-docker-auth/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'docker-auth']);

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

    try {
        app(IncusSandboxManager::class)->create($task, $repo);

        // mkdir runs as root so the push into /home/yak/.docker doesn't fail.
        Process::assertRan(fn ($p) => str_contains($p->command, "incus exec '{$container}' -- bash -c 'mkdir -p /home/yak/.docker'")
            && ! str_contains($p->command, 'sudo -u yak'));

        Process::assertRan(fn ($p) => str_contains($p->command, 'incus file push')
            && str_contains($p->command, escapeshellarg($dockerConfig))
            && str_contains($p->command, "'{$container}'/home/yak/.docker/config.json"));

        Process::assertRan(fn ($p) => str_contains($p->command, 'chown -R yak:yak /home/yak/.docker')
            && str_contains($p->command, 'chmod 600 /home/yak/.docker/config.json'));
    } finally {
        @unlink($dockerConfig);
    }
});

it('skips pushing the docker config when the host source file is missing', function () {
    $missing = sys_get_temp_dir() . '/yak-docker-missing-' . uniqid() . '.json';
    config()->set('yak.sandbox.docker_config_source', $missing);

    $repo = Repository::factory()->create([
        'slug' => 'no-docker-auth',
        'sandbox_snapshot' => 'yak-tpl-no-docker-auth/ready',
        'sandbox_base_version' => 2,
    ]);
    $task = YakTask::factory()->create(['repo' => 'no-docker-auth']);

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

    Process::assertNotRan(fn ($p) => str_contains($p->command, '/home/yak/.docker/config.json'));
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'mkdir -p /home/yak/.docker'));
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

it('builds streamExec argv with no shell wrapper so proc_terminate reaches incus directly', function () {
    config()->set('yak.agent_passthrough_env', 'NODE_AUTH_TOKEN,NPM_TOKEN');

    $manager = app(IncusSandboxManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('buildExecArgv');
    $method->setAccessible(true);

    $argv = $method->invoke($manager, 'task-42', 'echo hi', false);

    // Argv is executed directly (proc_open array form). The first token
    // must be `incus` — not `sh` / `bash` — so PHP's child pid IS the
    // incus process and proc_terminate signals hit it.
    expect($argv[0])->toBe('incus');
    expect($argv)->toContain('task-42');
    expect($argv)->toContain('sudo', '-u', 'yak', '-H', 'bash', '-c', 'echo hi');
    expect($argv)->toContain('--preserve-env=NODE_AUTH_TOKEN,NPM_TOKEN');
});

it('builds streamExec argv without sudo when run as root', function () {
    $manager = app(IncusSandboxManager::class);
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('buildExecArgv');
    $method->setAccessible(true);

    $argv = $method->invoke($manager, 'task-42', 'echo hi', true);

    expect($argv)->toBe(['incus', 'exec', 'task-42', '--', 'bash', '-c', 'echo hi']);
});

/**
 * Helper: prepare an isolated claude config dir with a host credentials file
 * whose claudeAiOauth.expiresAt is set to $hostExpiresAt. Returns the path.
 */
function primeClaudeConfigDir(int $hostExpiresAt): string
{
    $dir = sys_get_temp_dir() . '/yak-claude-test-' . uniqid();
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/.credentials.json', json_encode([
        'claudeAiOauth' => [
            'accessToken' => 'host-access',
            'refreshToken' => 'host-refresh',
            'expiresAt' => $hostExpiresAt,
            'scopes' => ['user:inference'],
            'subscriptionType' => 'max',
        ],
    ]));
    config()->set('yak.sandbox.claude_config_source', $dir);

    return $dir;
}

/**
 * Helper: Process::fake closure that simulates `incus file pull` by extracting
 * the destination path from the command and writing $payload there. Non-pull
 * commands return exit 0 so the surrounding flow can run.
 */
function fakeIncusFilePullThatWrites(string $payload): void
{
    Process::fake(function ($process) use ($payload) {
        $command = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
        if (str_contains($command, 'incus file pull')) {
            // The command ends `... <remote> '<localDest>' 2>/dev/null` — pull the
            // last single-quoted token before the stderr redirect.
            if (preg_match("/'([^']+)'\\s+2>\\/dev\\/null\$/", $command, $m)) {
                file_put_contents($m[1], $payload);
            }

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0);
    });
}

it('adopts sandbox credentials when pulled expiresAt is newer than host', function () {
    $dir = primeClaudeConfigDir(hostExpiresAt: 1_000_000_000_000);

    $rotated = json_encode([
        'claudeAiOauth' => [
            'accessToken' => 'rotated-access',
            'refreshToken' => 'rotated-refresh',
            'expiresAt' => 2_000_000_000_000,
            'scopes' => ['user:inference'],
            'subscriptionType' => 'max',
        ],
    ]);

    fakeIncusFilePullThatWrites($rotated);

    app(IncusSandboxManager::class)->pullClaudeCredentials('task-1');

    $decoded = json_decode((string) file_get_contents($dir . '/.credentials.json'), true);
    expect($decoded['claudeAiOauth']['refreshToken'])->toBe('rotated-refresh');
    expect($decoded['claudeAiOauth']['expiresAt'])->toBe(2_000_000_000_000);
});

it('leaves host credentials untouched when pulled expiresAt is not newer', function () {
    $dir = primeClaudeConfigDir(hostExpiresAt: 2_000_000_000_000);

    $stale = json_encode([
        'claudeAiOauth' => [
            'accessToken' => 'host-access',
            'refreshToken' => 'host-refresh',
            'expiresAt' => 2_000_000_000_000,
            'scopes' => ['user:inference'],
            'subscriptionType' => 'max',
        ],
    ]);

    fakeIncusFilePullThatWrites($stale);

    app(IncusSandboxManager::class)->pullClaudeCredentials('task-1');

    $decoded = json_decode((string) file_get_contents($dir . '/.credentials.json'), true);
    expect($decoded['claudeAiOauth']['refreshToken'])->toBe('host-refresh');
});

it('leaves host credentials untouched when pulled file lacks expiresAt', function () {
    $dir = primeClaudeConfigDir(hostExpiresAt: 1_000_000_000_000);

    fakeIncusFilePullThatWrites(json_encode(['firstStartTime' => '2026-01-01']));

    app(IncusSandboxManager::class)->pullClaudeCredentials('task-1');

    $decoded = json_decode((string) file_get_contents($dir . '/.credentials.json'), true);
    expect($decoded['claudeAiOauth']['refreshToken'])->toBe('host-refresh');
});

it('is a no-op when host has no credentials file yet', function () {
    $dir = sys_get_temp_dir() . '/yak-claude-test-' . uniqid();
    mkdir($dir, 0700, true);
    config()->set('yak.sandbox.claude_config_source', $dir);

    Process::fake(['*' => Process::result(exitCode: 0)]);

    app(IncusSandboxManager::class)->pullClaudeCredentials('task-1');

    // No incus file pull should have been attempted at all.
    Process::assertNotRan(fn ($p) => str_contains($p->command, 'incus file pull'));
    expect(is_file($dir . '/.credentials.json'))->toBeFalse();
});

it('leaves host credentials untouched when incus file pull fails', function () {
    $dir = primeClaudeConfigDir(hostExpiresAt: 1_000_000_000_000);

    Process::fake([
        'incus file pull *' => Process::result(exitCode: 1),
        '*' => Process::result(exitCode: 0),
    ]);

    app(IncusSandboxManager::class)->pullClaudeCredentials('task-1');

    $decoded = json_decode((string) file_get_contents($dir . '/.credentials.json'), true);
    expect($decoded['claudeAiOauth']['refreshToken'])->toBe('host-refresh');
});
