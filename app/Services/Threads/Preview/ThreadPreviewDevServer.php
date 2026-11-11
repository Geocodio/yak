<?php

namespace App\Services\Threads\Preview;

use App\DataTransferObjects\PreviewManifest;
use App\Jobs\Threads\StartThreadPreviewJob;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Support\SecretRedactor;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Starts and watches a thread's dev server from its `.yak/preview.yml`. The
 * state lives in the cache: it is only a hint for the waking page and panel,
 * the health probe is the truth.
 */
class ThreadPreviewDevServer
{
    private const STATE_TTL_SECONDS = 3600;

    private const READY_SECONDS = 30;

    private const LOG_PATH = '/tmp/yak-preview.log';

    private const LOG_LINES = 40;

    public function __construct(
        private SandboxManager $sandboxes,
        private ThreadPreviewManifestReader $reader,
    ) {}

    public function probe(Thread $thread, ?PreviewManifest $manifest = null): bool
    {
        $manifest ??= $this->reader->read($thread)->manifest;

        return $manifest !== null && $this->runProbe($thread, $manifest)->successful();
    }

    /**
     * Returns the state (ready, starting, failing, no_manifest or invalid_manifest)
     * and, when the dev server is down, starts it once in the background.
     */
    public function ensureStarted(Thread $thread): string
    {
        $result = $this->reader->read($thread);

        if ($result->manifest !== null) {
            Cache::put($this->key('manifest', $thread), ['port' => $result->manifest->port, 'coldStart' => $result->manifest->coldStart], self::STATE_TTL_SECONDS);
        }

        if ($result->manifest === null) {
            return $this->remember($thread, $result->status === 'invalid' ? 'invalid_manifest' : 'no_manifest', $result->error);
        }

        if ($this->probe($thread, $result->manifest)) {
            return $this->remember($thread, 'ready', port: $result->manifest->port);
        }

        if ($result->manifest->coldStart === '') {
            return $this->remember($thread, 'failing', 'The dev server is not answering and preview.yml has no cold_start.');
        }

        $window = $result->manifest->coldStartTimeoutSeconds + 60;

        if (Cache::add($this->key('starting', $thread), true, $window)) {
            $this->remember($thread, 'starting');
            StartThreadPreviewJob::dispatch($thread);
        }

        return $this->state($thread);
    }

    /**
     * The port and cold_start the last manifest read found, for the waking card. Nulls until a read happened.
     *
     * @return array{port: ?int, coldStart: ?string}
     */
    public function manifestHint(Thread $thread): array
    {
        $hint = Cache::get($this->key('manifest', $thread));

        return ['port' => $hint['port'] ?? null, 'coldStart' => ($hint['coldStart'] ?? '') !== '' ? $hint['coldStart'] : null];
    }

    public function state(Thread $thread): string
    {
        return Cache::get($this->key('state', $thread), 'starting');
    }

    public function error(Thread $thread): ?string
    {
        return Cache::get($this->key('error', $thread));
    }

    /** @return list<string> */
    public function logTail(Thread $thread): array
    {
        return Cache::get($this->key('log', $thread), []);
    }

    /** Runs `cold_start` in the background and waits for the probe; one run per thread at a time. */
    public function runColdStart(Thread $thread): void
    {
        $lock = Cache::lock($this->key('start', $thread), 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $manifest = $this->reader->read($thread)->manifest;

            if ($manifest === null || $manifest->coldStart === '') {
                return;
            }

            $this->sandboxes->exec($thread, [
                'bash', '-lc', 'nohup setsid bash -lc "$1" > ' . self::LOG_PATH . ' 2>&1 < /dev/null &', '_', $manifest->coldStart,
            ]);

            $this->awaitHealthy($thread, $manifest);
        } finally {
            Cache::forget($this->key('starting', $thread));
            $lock->release();
        }
    }

    private function awaitHealthy(Thread $thread, PreviewManifest $manifest): void
    {
        $deadline = now()->addSeconds($manifest->coldStartTimeoutSeconds);

        do {
            $probe = $this->runProbe($thread, $manifest);

            if ($probe->successful()) {
                $this->remember($thread, 'ready', port: $manifest->port);
                $this->captureLog($thread);

                return;
            }

            Sleep::for(1)->second();
        } while (now()->lessThan($deadline));

        $this->captureLog($thread);
        $this->remember($thread, 'failing', trim($probe->errorOutput() . "\n" . $probe->output()) ?: 'The dev server never answered the health probe.');
    }

    private function runProbe(Thread $thread, PreviewManifest $manifest): ProcessResult
    {
        return $this->sandboxes->exec($thread, [
            'curl', '-fsS', '-o', '/dev/null', '--max-time', (string) $manifest->healthProbeTimeoutSeconds,
            'http://127.0.0.1:' . $manifest->port . $manifest->healthProbePath,
        ]);
    }

    private function captureLog(Thread $thread): void
    {
        $output = $this->sandboxes->exec($thread, ['tail', '-n', (string) self::LOG_LINES, self::LOG_PATH])->output();
        $lines = array_slice(explode("\n", rtrim(SecretRedactor::redact($output))), -self::LOG_LINES);

        Cache::put($this->key('log', $thread), $lines, self::STATE_TTL_SECONDS);
    }

    /**
     * The port of a dev server that answered its probe within the last
     * READY_SECONDS and has not been stopped or paused since, so a burst of
     * requests skips the probe.
     */
    public function readyPort(Thread $thread): ?int
    {
        $ready = Cache::get($this->key('ready', $thread));

        if (! is_array($ready) || ($thread->sandbox_state_changed_at !== null && $ready['at'] < $thread->sandbox_state_changed_at->getTimestamp())) {
            return null;
        }

        return $ready['port'];
    }

    private function remember(Thread $thread, string $state, ?string $error = null, ?int $port = null): string
    {
        Cache::put($this->key('state', $thread), $state, self::STATE_TTL_SECONDS);

        if ($port !== null) {
            Cache::put($this->key('ready', $thread), ['port' => $port, 'at' => now()->getTimestamp()], self::READY_SECONDS);
        }

        $error === null ? Cache::forget($this->key('error', $thread)) : Cache::put($this->key('error', $thread), SecretRedactor::redact($error), self::STATE_TTL_SECONDS);

        return $state;
    }

    private function key(string $name, Thread $thread): string
    {
        return "thread-preview:{$name}:{$thread->id}";
    }
}
