<?php

namespace App\Services\Threads;

use App\Enums\ThreadSandboxState;
use App\Exceptions\DeploymentStartTimeoutException;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\DeploymentContainerManager;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\SandboxManager;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * Real thread sandboxes on Incus, one container per thread. The sandbox state
 * lives on the thread row; this class only performs the Incus operations.
 */
class IncusThreadSandboxManager implements SandboxManager
{
    private const COMMAND_TIMEOUT = 600;

    private const QUERY_TIMEOUT = 30;

    private const SANDBOX_USER = '1001';

    private const POOL_CACHE_KEY = 'thread-sandbox:pool-used-percent';

    private const POOL_CACHE_SECONDS = 15;

    /** @var Closure(string): ?string */
    private Closure $reader;

    /**
     * @param  (Closure(string): ?string)|null  $reader  Returns a file's contents or null; replaced in tests.
     */
    public function __construct(private IncusSandboxManager $taskSandboxes, ?Closure $reader = null)
    {
        $this->reader = $reader ?? static fn (string $path): ?string => is_readable($path)
            ? (file_get_contents($path) ?: null)
            : null;
    }

    public function containerName(Thread $thread): string
    {
        // A research child shares its parent's container.
        return $thread->sandbox_container ?? "yak-thread-{$thread->id}";
    }

    public function create(Thread $thread): void
    {
        $container = $this->containerName($thread);
        $repository = Repository::where('slug', $thread->repo)->firstOrFail();
        $source = $this->taskSandboxes->resolveSource($repository);

        $this->mustRun('create', ['incus', 'copy', $source, $container]);

        $cpu = (int) config('yak.sandbox.cpu_limit', 4);
        $memory = (string) config('yak.sandbox.memory_limit', '8GB');
        $disk = (string) config('yak.sandbox.disk_limit', '30GB');
        $this->mustRun('create', ['incus', 'config', 'set', $container, "limits.cpu={$cpu}", "limits.memory={$memory}"]);
        $this->query(['incus', 'config', 'device', 'set', $container, 'root', "size={$disk}"]);

        $this->recordContainer($thread, $container);
    }

    public function start(Thread $thread): void
    {
        $container = $this->containerName($thread);
        $this->mustRun('start', ['incus', 'start', $container]);

        $timeout = (int) config('yak.sandbox.ready_timeout_seconds', 60);
        $probe = null;
        for ($attempt = 0; $attempt < $timeout; $attempt++) {
            $probe = Process::timeout(10)->run(['incus', 'exec', $container, '--', 'true']);
            if ($probe->successful()) {
                $this->recordAddress($thread);
                $this->taskSandboxes->pushMcpConfig($container);

                return;
            }
            Sleep::for(1)->second();
        }

        throw SandboxCommandFailed::for('start (not ready within ' . $timeout . 's)', $probe ?? Process::result('', '', 1));
    }

    public function pause(Thread $thread): void
    {
        $this->mustRun('pause', ['incus', 'pause', $this->containerName($thread)]);
    }

    public function resume(Thread $thread): void
    {
        $this->mustRun('resume', ['incus', 'resume', $this->containerName($thread)]);
        $this->recordAddress($thread);
    }

    public function stop(Thread $thread, int $timeoutSeconds): void
    {
        $container = $this->containerName($thread);
        $result = Process::timeout($timeoutSeconds + 30)->run(['incus', 'stop', $container, '--timeout', (string) $timeoutSeconds]);

        if ($result->failed()) {
            $this->mustRun('stop', ['incus', 'stop', $container, '--force']);
        }

        $thread->update(['sandbox_ip' => null]);
    }

    public function destroy(Thread $thread): void
    {
        $result = Process::timeout(300)->run(['incus', 'delete', $this->containerName($thread), '--force']);

        if ($result->failed() && ! str_contains(strtolower($result->errorOutput()), 'not found')) {
            throw SandboxCommandFailed::for('destroy', $result);
        }

        $thread->update(['sandbox_ip' => null]);
    }

    /**
     * Returns non-zero exits to the caller; throws only when Incus itself could not run the command.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        $argv = ['incus', 'exec', $this->containerName($thread), '--cwd', (string) config('yak.sandbox.workspace_path', '/workspace'), '--env', 'HOME=/home/yak'];
        foreach ($env as $name => $value) {
            array_push($argv, '--env', "{$name}={$value}");
        }
        // setpriv gives the sandbox user its own group and supplementary groups (docker);
        // `incus exec --user` alone would leave the command in group root.
        array_push($argv, '--', 'setpriv', '--reuid=' . self::SANDBOX_USER, '--regid=' . self::SANDBOX_USER, '--init-groups', '--', ...$command);

        $process = Process::timeout(self::COMMAND_TIMEOUT);
        if ($stdin !== null) {
            $process = $process->input($stdin);
        }
        $result = $process->run($argv);

        if ($result->failed() && preg_match('/^Error: .*(not found|not running|connection|websocket)/mi', $result->errorOutput())) {
            throw SandboxCommandFailed::for('exec', $result);
        }

        return $result;
    }

    public function copyFrom(Thread $source, Thread $target): void
    {
        $container = $this->containerName($target);
        $this->mustRun('copy', ['incus', 'copy', $this->containerName($source), $container, '--stateless']);
        $this->recordContainer($target, $container);
    }

    public function state(Thread $thread): ThreadSandboxState
    {
        return $thread->sandbox_state;
    }

    /** One `incus info`, for the sweep to catch a container changed behind our back. */
    public function reconcile(Thread $thread): ThreadSandboxState
    {
        $result = Process::timeout(self::QUERY_TIMEOUT)->run(['incus', 'info', $this->containerName($thread)]);

        if ($result->failed()) {
            return ThreadSandboxState::Destroyed;
        }

        preg_match('/^Status:\s*(\w+)/mi', $result->output(), $match);

        return match (strtoupper($match[1] ?? '')) {
            'RUNNING' => ThreadSandboxState::Running,
            'FROZEN' => ThreadSandboxState::Paused,
            default => ThreadSandboxState::Stopped,
        };
    }

    /** MemAvailable plus the ZFS ARC above its minimum, which the kernel gives back under pressure. */
    public function availableMemoryBytes(): int
    {
        $available = 0;
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', ($this->reader)('/proc/meminfo') ?? '', $match)) {
            $available = (int) $match[1] * 1024;
        }

        $arc = ($this->reader)('/proc/spl/kstat/zfs/arcstats');
        if ($arc !== null
            && preg_match('/^size\s+\d+\s+(\d+)/m', $arc, $size)
            && preg_match('/^c_min\s+\d+\s+(\d+)/m', $arc, $min)) {
            $available += max(0, (int) $size[1] - (int) $min[1]);
        }

        return $available;
    }

    public function poolUsedPercent(): float
    {
        return Cache::remember(self::POOL_CACHE_KEY, self::POOL_CACHE_SECONDS, function (): float {
            $pool = (string) config('yak.sandbox.incus_pool', 'default');
            $result = Process::timeout(self::QUERY_TIMEOUT)->run(['incus', 'storage', 'info', $pool, '--resource']);

            if ($result->failed()
                || ! preg_match('/Space used:\s*([\d.]+)\s*(\w+)/i', $result->output(), $used)
                || ! preg_match('/Total space:\s*([\d.]+)\s*(\w+)/i', $result->output(), $total)) {
                throw SandboxCommandFailed::for('pool usage', $result);
            }

            $totalBytes = $this->toBytes((float) $total[1], $total[2]);

            return $totalBytes > 0 ? round($this->toBytes((float) $used[1], $used[2]) / $totalBytes * 100, 2) : 100.0;
        });
    }

    private function toBytes(float $value, string $unit): float
    {
        $exponent = ['b' => 0, 'kib' => 1, 'mib' => 2, 'gib' => 3, 'tib' => 4, 'pib' => 5][strtolower($unit)] ?? 0;

        return $value * 1024 ** $exponent;
    }

    /** Best effort: a missed lookup clears any previous address so the preview waker resolves and records it itself. */
    private function recordAddress(Thread $thread): void
    {
        try {
            $thread->update(['sandbox_ip' => app(DeploymentContainerManager::class)->resolveContainerIp($this->containerName($thread))]);
        } catch (DeploymentStartTimeoutException) {
            $thread->update(['sandbox_ip' => null]);
        }
    }

    private function recordContainer(Thread $thread, string $container): void
    {
        $thread->update([
            'sandbox_container' => $container,
            'sandbox_host' => (string) config('yak.sandbox.incus_remote', 'local'),
        ]);
    }

    /**
     * @param  list<string>  $argv
     */
    private function mustRun(string $operation, array $argv): void
    {
        $result = Process::timeout(self::COMMAND_TIMEOUT)->run($argv);

        if ($result->failed()) {
            throw SandboxCommandFailed::for($operation, $result);
        }
    }

    /**
     * Best-effort command whose failure is ignored.
     *
     * @param  list<string>  $argv
     */
    private function query(array $argv): void
    {
        Process::timeout(self::QUERY_TIMEOUT)->run($argv);
    }
}
