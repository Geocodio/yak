<?php

namespace App\Services\Threads;

use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Puts the runner bundle the app image carries into a sandbox before a runner starts there. The bundle has
 * two parts with their own versions: `deps` (node_modules, rarely changes) and `src` (the runner's own files).
 * Only a part whose version differs from the sandbox's /opt/yak-runner/.{deps,src}-version is pushed, a live
 * runner is never swapped out, and a failed push is reported and swallowed: the runner already in the sandbox
 * (baked into yak-base) still starts.
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
        $deps = $this->bundleVersion($directory, 'deps');
        $src = $this->bundleVersion($directory, 'src');

        if ($deps === null || $src === null) {
            return;
        }

        $cacheKey = "runner-delivery:{$container}";

        if (Cache::get($cacheKey) === "{$deps}:{$src}") {
            return;
        }

        try {
            [$installedDeps, $installedSrc] = $this->installedVersions($container);
            $pushDeps = $installedDeps !== $deps;
            $pushSrc = $installedSrc !== $src;

            if (($pushDeps || $pushSrc) && ! $this->push($container, $directory, $pushDeps, $pushSrc, $deps, $src)) {
                return;
            }

            Cache::put($cacheKey, "{$deps}:{$src}", self::CACHE_SECONDS);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Runner bundle delivery failed; the sandbox keeps its current runner', [
                'container' => $container,
                'error' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    /**
     * The version of one bundle part, or null when the image carries no such part.
     */
    private function bundleVersion(string $directory, string $part): ?string
    {
        $version = is_file("{$directory}/{$part}.version") ? trim((string) file_get_contents("{$directory}/{$part}.version")) : '';

        return $version !== '' && is_file("{$directory}/{$part}.tgz") ? $version : null;
    }

    /**
     * @return array{0: string, 1: string} the sandbox's deps and src versions, empty when absent
     */
    private function installedVersions(string $container): array
    {
        $directory = self::RUNNER_DIRECTORY;
        $command = "printf '%s\\n%s\\n' \"$(cat {$directory}/.deps-version 2>/dev/null)\" \"$(cat {$directory}/.src-version 2>/dev/null)\"";
        $lines = explode("\n", $this->sandbox->run($container, $command, timeout: 10, asRoot: true)->output());

        return [trim($lines[0]), trim($lines[1] ?? '')];
    }

    /**
     * @return bool false when a runner is live in the sandbox and the swap was skipped
     */
    private function push(string $container, string $directory, bool $pushDeps, bool $pushSrc, string $deps, string $src): bool
    {
        foreach (['deps' => $pushDeps, 'src' => $pushSrc] as $part => $changed) {
            if ($changed) {
                $this->sandbox->pushFile($container, "{$directory}/{$part}.tgz", "/tmp/yak-runner-{$part}.tgz");
            }
        }

        $result = $this->sandbox->run($container, $this->swapScript($pushDeps, $pushSrc, $deps, $src), timeout: 300, asRoot: true);

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
     * Assembles the new directory from hard links to the live one plus the pushed parts, then swaps with two
     * renames, so a failed unpack leaves the old runner and an unchanged part is never copied.
     */
    private function swapScript(bool $pushDeps, bool $pushSrc, string $deps, string $src): string
    {
        $directory = self::RUNNER_DIRECTORY;
        $lines = [
            'set -e',
            "if pgrep -f '[/]opt/yak-runner/runner.mjs' >/dev/null; then rm -f /tmp/yak-runner-deps.tgz /tmp/yak-runner-src.tgz; exit 3; fi",
            "rm -rf {$directory}.new {$directory}.old",
            "if [ -d {$directory} ]; then cp -al {$directory} {$directory}.new; else mkdir -p {$directory}.new; fi",
        ];

        if ($pushDeps) {
            $lines[] = "rm -rf {$directory}.new/node_modules";
            $lines[] = "tar -xzf /tmp/yak-runner-deps.tgz -C {$directory}.new";
            $lines[] = "echo {$deps} > {$directory}.new/.deps-version";
        }

        if ($pushSrc) {
            $lines[] = "find {$directory}.new -mindepth 1 -maxdepth 1 ! -name node_modules ! -name .deps-version -exec rm -rf {} +";
            $lines[] = "tar -xzf /tmp/yak-runner-src.tgz -C {$directory}.new";
            $lines[] = "echo {$src} > {$directory}.new/.src-version";
        }

        $lines[] = "test -f {$directory}.new/runner.mjs && test -d {$directory}.new/node_modules";
        $lines[] = "if [ -d {$directory} ]; then mv {$directory} {$directory}.old; fi";
        $lines[] = "mv {$directory}.new {$directory}";
        $lines[] = "rm -rf {$directory}.old /tmp/yak-runner-deps.tgz /tmp/yak-runner-src.tgz";

        return implode("\n", $lines);
    }
}
