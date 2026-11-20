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
    public string $installedVersion = '';

    public bool $runnerLive = false;

    public bool $pushFails = false;

    /** @var list<string> */
    public array $pushed = [];

    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null, array $env = []): ProcessResult
    {
        if (str_contains($command, '/opt/yak-runner/.version')) {
            $this->commands[] = $command;

            return Process::result($this->installedVersion);
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
    file_put_contents("{$this->bundle}/yak-runner.tgz", 'tarball');
    file_put_contents("{$this->bundle}/version", "abc123\n");
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

it('pushes and unpacks the bundle when the sandbox runs a different version', function () {
    $this->sandbox->installedVersion = 'old999';

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe(['yak-thread-1:/tmp/yak-runner-bundle.tgz'])
        ->and($this->sandbox->commandsMatching('yak-runner.new'))->toHaveCount(1)
        ->and($this->sandbox->commandsMatching('yak-runner.new')[0])
        ->toContain("pgrep -f '[/]opt/yak-runner/runner.mjs'")
        ->toContain('mv /opt/yak-runner.new /opt/yak-runner');
});

it('pushes when the sandbox has no version file', function () {
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toHaveCount(1);
});

it('does not push when the sandbox already runs the bundle version', function () {
    $this->sandbox->installedVersion = "abc123\n";

    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toBe([])
        ->and($this->sandbox->commandsMatching('yak-runner.new'))->toBe([]);
});

it('remembers a verified sandbox so the next start does not shell out', function () {
    $this->delivery->ensure('yak-thread-1');
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toHaveCount(1)
        ->and($this->sandbox->commandsMatching('/opt/yak-runner/.version'))->toHaveCount(1);
});

it('delivers again when the bundle changes', function () {
    $this->delivery->ensure('yak-thread-1');
    file_put_contents("{$this->bundle}/version", 'def456');
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->pushed)->toHaveCount(2);
});

it('leaves a sandbox with a live runner untouched and retries on the next start', function () {
    $this->sandbox->runnerLive = true;

    $this->delivery->ensure('yak-thread-1');

    $this->sandbox->runnerLive = false;
    $this->delivery->ensure('yak-thread-1');

    expect($this->sandbox->commandsMatching('yak-runner.new'))->toHaveCount(2)
        ->and($this->sandbox->pushed)->toHaveCount(2)
        ->and(Cache::get('runner-delivery:yak-thread-1'))->toBe('abc123');
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

    expect($this->sandbox->pushed)->toBe(['thread-1:/tmp/yak-runner-bundle.tgz']);
});
