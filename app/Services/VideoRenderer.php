<?php

namespace App\Services;

use App\DataTransferObjects\WalkthroughTimeline;
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

        // Stage into a per-render directory that the worker user owns and
        // that Remotion serves as its public dir, so staticFile() resolves
        // the clip without anything under /app/video being writable.
        $stagingRoot = rtrim((string) config('yak.video.render_staging_path'), '/');
        $stagingDir = $stagingRoot . '/' . bin2hex(random_bytes(6));
        if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0775, true) && ! is_dir($stagingDir)) {
            throw new RuntimeException("cannot create render staging dir: {$stagingDir}");
        }

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
            @unlink($stagedPath);
            @rmdir($stagingDir);
        }
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
