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
