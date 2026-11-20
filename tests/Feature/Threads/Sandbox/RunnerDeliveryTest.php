<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\IncusSandboxManager;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\RunnerDelivery;
use App\Services\Threads\ThreadCommands;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSandboxManager;

/**
 * Records pushes and answers the sandbox's installed version, as an Incus sandbox would.
 */
class DeliverySandbox extends FakeSandboxManager
{
    public string $installedDeps = '';

    public string $installedSrc = '';

    public bool $runnerLive = false;

    public bool $pushFails = false;

    /** @var list<string> */
    public array $pushed = [];

    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null, array $env = []): ProcessResult
    {
        if (str_contains($command, '.deps-version 2>/dev/null')) {
            $this->commands[] = $command;

            return Process::result("{$this->installedDeps}\n{$this->installedSrc}\n");
        }

        if (str_contains($command, 'yak-runner.new')) {
            $this->commands[] = $command;

            return Process::result('', '', $this->runnerLive ? 3 : 0);
        }

        return parent::run($containerName, $command, $timeout, $asRoot, $input, $output, $env);
    }

    public function pushFile(string $containerName, string $localPath, string $remotePath): void
    {
        if ($this->pushFails) {
            throw new RuntimeException('incus file push failed');
        }

        $this->pushed[] = "{$containerName}:{$remotePath}";
    }
}

beforeEach(function () {
    $this->bundle = sys_get_temp_dir() . '/runner-bundle-' . uniqid();
    mkdir($this->bundle);
    foreach (['deps' => 'dep111', 'src' => 'src111'] as $part => $version) {
        file_put_contents("{$this->bundle}/{$part}.tgz", 'tarball');
        file_put_contents("{$this->bundle}/{$part}.version", "{$version}\n");
    }
    config(['yak.sandbox.runner_bundle_path' => $this->bundle]);
    Cache::flush();

    $this->sandbox = new DeliverySandbox;
    app()->instance(IncusSandboxManager::class, $this->sandbox);
    $this->delivery = new RunnerDelivery($this->sandbox);
});

afterEach(function () {
    array_map('unlink', glob("{$this->bundle}/*"));
    rmdir($this->bundle);
});

it('pushes both parts to a sandbox that has neither version', function () {
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe(['yak-thread-1:/tmp/yak-runner-deps.tgz', 'yak-thread-1:/tmp/yak-runner-src.tgz'])
        ->and($this->sandbox->commandsMatching('yak-runner.new'))->toHaveCount(1)
        ->and($this->sandbox->commandsMatching('yak-runner.new')[0])
        ->toContain("pgrep -f '[/]opt/yak-runner/runner.mjs'")
        ->toContain('tar -xzf /tmp/yak-runner-deps.tgz')
        ->toContain('tar -xzf /tmp/yak-runner-src.tgz')
        ->toContain('mv /opt/yak-runner.new /opt/yak-runner');
});

it('pushes only the source when only the source changed', function () {
    $this->sandbox->installedDeps = 'dep111';
    $this->sandbox->installedSrc = 'src000';

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe(['yak-thread-1:/tmp/yak-runner-src.tgz'])
        ->and($this->sandbox->commandsMatching('yak-runner.new')[0])
        ->toContain('cp -al /opt/yak-runner /opt/yak-runner.new')
        ->toContain('tar -xzf /tmp/yak-runner-src.tgz')
        ->not->toContain('yak-runner-deps.tgz -C');
});

it('pushes only the dependencies when the lockfile version changed', function () {
    $this->sandbox->installedDeps = 'dep000';
    $this->sandbox->installedSrc = 'src111';

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe(['yak-thread-1:/tmp/yak-runner-deps.tgz'])
        ->and($this->sandbox->commandsMatching('yak-runner.new')[0])->not->toContain('yak-runner-src.tgz -C');
});

it('does not push when the sandbox already runs both versions', function () {
    $this->sandbox->installedDeps = 'dep111';
    $this->sandbox->installedSrc = 'src111';

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe([])
        ->and($this->sandbox->commandsMatching('yak-runner.new'))->toBe([]);
});

it('remembers a delivered sandbox so the next start does not shell out', function () {
    $this->delivery->ensure('yak-thread-1');
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toHaveCount(2)
        ->and($this->sandbox->commandsMatching('.deps-version 2>/dev/null'))->toHaveCount(1);
});

it('delivers again when a part of the bundle changes', function () {
    $this->delivery->ensure('yak-thread-1');
    file_put_contents("{$this->bundle}/src.version", 'src222');
    $this->sandbox->installedDeps = 'dep111';
    $this->sandbox->installedSrc = 'src111';
    $this->sandbox->pushed = [];

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe(['yak-thread-1:/tmp/yak-runner-src.tgz']);
});

it('leaves a sandbox with a live runner untouched and retries on the next start', function () {
    $this->sandbox->runnerLive = true;

    $this->delivery->ensure('yak-thread-1');

    $this->sandbox->runnerLive = false;
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->commandsMatching('yak-runner.new'))->toHaveCount(2)
        ->and($this->sandbox->pushed)->toHaveCount(4)
        ->and(Cache::get('runner-delivery:yak-thread-1'))->toBe('dep111:src111');
});

it('logs and reports a failed push instead of throwing, and tries again next time', function () {
    Log::shouldReceive('channel')->with('yak')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with(Mockery::pattern('/delivery failed/'), Mockery::on(fn (array $context): bool => $context['container'] === 'yak-thread-1'));
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported) {
        $reported[] = $exception->getMessage();
    })->stop();
    $this->sandbox->pushFails = true;

    $this->delivery->ensure('yak-thread-1');

    expect($reported)->toBe(['incus file push failed'])
        ->and(Cache::get('runner-delivery:yak-thread-1'))->toBeNull();
});

it('does nothing when the image carries no bundle', function () {
    config(['yak.sandbox.runner_bundle_path' => $this->bundle . '/missing']);

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->commands)->toBe([]);
});

it('delivers before ThreadCommands starts a thread runner and still starts it when delivery fails', function () {
    $thread = Thread::factory()->create(['sandbox_container' => 'thread-1', 'sandbox_state' => 'running']);
    $account = ClaudeAccount::factory()->create();
    app(SandboxManager::class)->queueExecResult(Process::result("2: eth0    inet 10.100.0.7/24 scope global eth0\n"));
    $this->sandbox->pushFails = true;
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('issue')->andReturn('yakph-test');

    (new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), $placeholders, app(ClaudeModelCatalog::class), $this->delivery))
        ->startRunner($thread, $account, null, 'Be brief.');

    expect(app(BrokerStreams::class)->commands('threads:' . $thread->id)[0]['type'])->toBe('start_runner');

    $this->sandbox->pushFails = false;
    app(SandboxManager::class)->queueExecResult(Process::result("2: eth0    inet 10.100.0.7/24 scope global eth0\n"));

    (new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), $placeholders, app(ClaudeModelCatalog::class), $this->delivery))
        ->startRunner($thread, $account, null, 'Be brief.');

    expect($this->sandbox->pushed)->toBe(['thread-1:/tmp/yak-runner-deps.tgz', 'thread-1:/tmp/yak-runner-src.tgz']);
});
