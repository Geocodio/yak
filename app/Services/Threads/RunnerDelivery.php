<?php

namespace App\Services\Threads;

use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Puts the runner bundle the app image carries into a sandbox before a runner starts there. A sandbox
 * whose /opt/yak-runner/.version already matches is left alone, a live runner is never swapped out, and
 * a failed push is reported and swallowed: the runner baked into yak-base still starts.
 */
class RunnerDelivery
{
    public const RUNNER_DIRECTORY = '/opt/yak-runner';

    private const CACHE_SECONDS = 3600;

    private const RUNNING_EXIT_CODE = 3;

    public function __construct(private readonly IncusSandboxManager $sandbox) {}

    public function ensure(string $container): void
    {
        $directory = (string) config('yak.sandbox.runner_bundle_path');
        $tarball = "{$directory}/yak-runner.tgz";
        $version = is_file("{$directory}/version") ? trim((string) file_get_contents("{$directory}/version")) : '';

        if ($version === '' || ! is_file($tarball)) {
            return;
        }

        $cacheKey = "runner-delivery:{$container}";

        if (Cache::get($cacheKey) === $version) {
            return;
        }

        try {
            $installed = trim($this->sandbox->run($container, 'cat ' . self::RUNNER_DIRECTORY . '/.version 2>/dev/null', timeout: 10, asRoot: true)->output());

            if ($installed !== $version && ! $this->push($container, $tarball)) {
                return;
            }

            Cache::put($cacheKey, $version, self::CACHE_SECONDS);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Runner bundle delivery failed; the sandbox keeps its current runner', [
                'container' => $container,
                'error' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    /**
     * @return bool false when a runner is live in the sandbox and the swap was skipped
     */
    private function push(string $container, string $tarball): bool
    {
        $this->sandbox->pushFile($container, $tarball, '/tmp/yak-runner-bundle.tgz');

        $result = $this->sandbox->run($container, $this->swapScript(), timeout: 300, asRoot: true);

        if ($result->exitCode() === self::RUNNING_EXIT_CODE) {
            // A research child shares its parent's sandbox, so another runner can be live; it is never touched.
            return false;
        }

        if (! $result->successful()) {
            throw new RuntimeException('Unpacking the runner bundle failed: ' . trim($result->errorOutput() . $result->output()));
        }

        return true;
    }

    /**
     * Unpacks beside the live directory, then swaps with two renames, so a failed unpack leaves the old runner.
     */
    private function swapScript(): string
    {
        $directory = self::RUNNER_DIRECTORY;

        return <<<SH
set -e
if pgrep -f '[/]opt/yak-runner/runner.mjs' >/dev/null; then rm -f /tmp/yak-runner-bundle.tgz; exit 3; fi
rm -rf {$directory}.new {$directory}.old
mkdir -p {$directory}.new
tar -xzf /tmp/yak-runner-bundle.tgz -C {$directory}.new
test -f {$directory}.new/.version
if [ -d {$directory} ]; then mv {$directory} {$directory}.old; fi
mv {$directory}.new {$directory}
rm -rf {$directory}.old /tmp/yak-runner-bundle.tgz
SH;
    }
}
