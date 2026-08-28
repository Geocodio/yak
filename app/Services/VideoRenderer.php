<?php

namespace App\Services;

use App\DataTransferObjects\WalkthroughTimeline;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class VideoRenderer
{
    public function __construct(public string $videoDir) {}

    /**
     * Ask the composition's own `buildBlocks()` for the cut's shape before
     * rendering anything (spec §7). Runs on the host with `npx tsx`, the
     * same entry point the video project documents.
     */
    public function timeline(string $scriptPath, string $manifestPath, ?string $voiceoverJsonPath = null): WalkthroughTimeline
    {
        $command = [
            'npx', 'tsx', 'scripts/timeline.ts',
            '--script', $scriptPath,
            '--manifest', $manifestPath,
        ];

        if ($voiceoverJsonPath !== null) {
            $command[] = '--voiceover';
            $command[] = $voiceoverJsonPath;
        }

        $result = Process::path($this->videoDir)->timeout(120)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(
                'timeline.ts failed (exit ' . $result->exitCode() . '): ' . trim($result->errorOutput())
            );
        }

        return WalkthroughTimeline::fromJson($result->output());
    }

    public function render(string $webmPath, string $storyboardPath, string $outputPath): string
    {
        if (! file_exists($webmPath)) {
            throw new RuntimeException("walkthrough webm not found: {$webmPath}");
        }
        if (! file_exists($storyboardPath)) {
            throw new RuntimeException("storyboard.json not found: {$storyboardPath}");
        }

        $stagingDir = $this->makeStagingDir();

        $stagedName = 'walkthrough.webm';
        $stagedPath = "{$stagingDir}/{$stagedName}";
        if (! copy($webmPath, $stagedPath)) {
            throw new RuntimeException("failed to stage webm into {$stagedPath}");
        }

        try {
            $storyboardJson = file_get_contents($storyboardPath);
            if ($storyboardJson === false) {
                throw new RuntimeException("failed to read storyboard.json: {$storyboardPath}");
            }
            $storyboard = json_decode($storyboardJson, true);
            $props = json_encode([
                'videoUrl' => $stagedName,
                'storyboard' => $storyboard,
                'videoDurationSeconds' => $this->probeDurationSeconds($webmPath),
                'musicTrack' => null,
                // The `tier` prop belongs to the legacy composition
                // (WalkthroughV2); it is pinned here for that composition.
                'tier' => 'reviewer',
            ], JSON_UNESCAPED_SLASHES);

            $result = Process::path($this->videoDir)
                ->timeout(600)
                ->run([
                    'npx', 'remotion', 'render',
                    'src/index.ts', 'WalkthroughV2', $outputPath,
                    '--public-dir=' . $stagingDir . '/',
                    '--props=' . $props,
                ]);

            if (! $result->successful()) {
                throw new RuntimeException(
                    "Remotion render failed (exit {$result->exitCode()}): {$result->errorOutput()}"
                );
            }

            return $outputPath;
        } finally {
            File::deleteDirectory($stagingDir);
        }
    }

    /**
     * Render the v3 cut.
     *
     * Clips are staged into a directory the worker user owns and the
     * manifest handed to Remotion points at those absolute paths, so
     * nothing under /app/video ever needs to be writable (spec §11).
     *
     * @param  array<string, string>  $clipPaths  shot id => absolute path to its .webm
     * @param  array<string, array{file: string, durationSeconds: float}>|null  $voiceover
     * @param  array<string, mixed>  $theme
     */
    public function renderWalkthrough(
        string $scriptPath,
        string $manifestPath,
        array $clipPaths,
        ?array $voiceover,
        array $theme,
        ?string $publicOrigin,
        string $outputPath,
    ): string {
        $stagingDir = $this->makeStagingDir();

        try {
            $script = $this->readJson($scriptPath, 'script.json');
            $manifest = $this->readJson($manifestPath, 'manifest.json');

            if (! is_dir($stagingDir . '/shots') && ! mkdir($stagingDir . '/shots', 0775, true)) {
                throw new RuntimeException("cannot create staging shots dir in {$stagingDir}");
            }

            $shots = [];
            foreach ((array) ($manifest['shots'] ?? []) as $shot) {
                $id = (string) ($shot['id'] ?? '');
                $source = $clipPaths[$id] ?? null;

                if ($source === null || ! file_exists($source)) {
                    Log::channel('yak')->warning('VideoRenderer: dropping shot with no clip on disk', ['shot' => $id]);

                    continue;
                }

                $staged = "{$stagingDir}/shots/{$id}.webm";
                if (! copy($source, $staged)) {
                    throw new RuntimeException("failed to stage clip for shot {$id}");
                }

                // Relative to the staging public dir, not absolute: the
                // composition hands absolute paths to Remotion as `file://`
                // URLs, which its asset downloader refuses. A bare
                // `shots/<id>.webm` resolves through `staticFile()` against
                // `--public-dir`, which is exactly where the clip was staged.
                $shot['clip'] = "shots/{$id}.webm";
                $shots[] = $shot;
            }

            if ($shots === []) {
                throw new RuntimeException('no shot clips available to render');
            }

            $manifest['shots'] = $shots;

            $props = json_encode([
                'script' => $script,
                'manifest' => $manifest,
                'voiceover' => $voiceover,
                'theme' => $theme,
                'publicOrigin' => $publicOrigin,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $result = Process::path($this->videoDir)
                ->timeout(900)
                ->run([
                    'npx', 'remotion', 'render',
                    'src/index.ts', 'WalkthroughV3', $outputPath,
                    '--public-dir=' . $stagingDir . '/',
                    '--props=' . $props,
                ]);

            if (! $result->successful()) {
                throw new RuntimeException(
                    "Remotion render failed (exit {$result->exitCode()}): {$result->errorOutput()}"
                );
            }

            return $outputPath;
        } finally {
            File::deleteDirectory($stagingDir);
        }
    }

    /**
     * Create a fresh per-render staging directory under the configured root.
     *
     * The worker user owns this tree; /app/video/public is root-owned in the
     * image, so nothing may be written there.
     */
    private function makeStagingDir(): string
    {
        $root = rtrim((string) config('yak.video.render_staging_path'), '/');
        $dir = $root . '/' . bin2hex(random_bytes(6));

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create render staging dir: {$dir}");
        }

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, string $label): array
    {
        if (! file_exists($path)) {
            throw new RuntimeException("{$label} not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$label} is not valid JSON: {$path}");
        }

        return $decoded;
    }

    public function probeDurationSeconds(string $webmPath): ?float
    {
        $result = Process::run([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $webmPath,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $duration = (float) trim($result->output());

        return $duration > 0 ? $duration : null;
    }
}
