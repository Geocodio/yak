<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class VideoRenderer
{
    public function __construct(public string $videoDir) {}

    public function render(string $webmPath, string $storyboardPath, string $outputPath, string $tier = 'reviewer'): string
    {
        if (! file_exists($webmPath)) {
            throw new RuntimeException("walkthrough webm not found: {$webmPath}");
        }
        if (! file_exists($storyboardPath)) {
            throw new RuntimeException("storyboard.json not found: {$storyboardPath}");
        }

        $publicDir = "{$this->videoDir}/public";
        if (! is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $stagedName = '_render-' . bin2hex(random_bytes(6)) . '.webm';
        $stagedPath = "{$publicDir}/{$stagedName}";
        copy($webmPath, $stagedPath);

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
                'tier' => $tier,
            ], JSON_UNESCAPED_SLASHES);

            $result = Process::path($this->videoDir)
                ->timeout(600)
                ->run([
                    'npx', 'remotion', 'render',
                    'src/index.ts', 'Walkthrough', $outputPath,
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
        }
    }

    private function probeDurationSeconds(string $webmPath): ?float
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
