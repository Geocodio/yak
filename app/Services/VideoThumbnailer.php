<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Extracts a still frame from a rendered walkthrough mp4 and composites a
 * play-button overlay on top so GitHub PR bodies can embed a clickable
 * poster image that hints there's a video behind it.
 */
class VideoThumbnailer
{
    public function __construct(public string $overlayPath) {}

    public function generate(string $videoPath, string $outputPath): string
    {
        if (! file_exists($videoPath)) {
            throw new RuntimeException("video not found: {$videoPath}");
        }
        if (! file_exists($this->overlayPath)) {
            throw new RuntimeException("play overlay not found: {$this->overlayPath}");
        }

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        $result = Process::timeout(60)->run([
            'ffmpeg', '-y',
            '-ss', '00:00:01',
            '-i', $videoPath,
            '-i', $this->overlayPath,
            '-filter_complex', '[1:v]scale=iw*0.2:-1[pb];[0:v][pb]overlay=(W-w)/2:(H-h)/2',
            '-frames:v', '1',
            '-q:v', '3',
            $outputPath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException(
                "ffmpeg thumbnail generation failed (exit {$result->exitCode()}): {$result->errorOutput()}"
            );
        }

        return $outputPath;
    }
}
