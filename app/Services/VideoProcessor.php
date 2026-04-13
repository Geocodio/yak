<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class VideoProcessor
{
    /** Minimum seconds of inactivity before we consider a segment idle */
    private const IDLE_THRESHOLD_SECONDS = 3.0;

    /** Scene change detection threshold (0.0-1.0, lower = more sensitive) */
    private const SCENE_THRESHOLD = 0.02;

    /** Speed multiplier for idle segments */
    private const IDLE_SPEED = 8;

    /** Minimum duration of the first activity to consider it a real start */
    private const MIN_START_ACTIVITY_SECONDS = 0.5;

    /**
     * Process a raw walkthrough video: trim dead start, speed up idle sections.
     * Returns the path to the processed video, or the original path if processing fails.
     */
    public static function process(string $inputPath): string
    {
        if (! file_exists($inputPath)) {
            return $inputPath;
        }

        $duration = self::getDuration($inputPath);
        if ($duration === null || $duration < 5.0) {
            return $inputPath;
        }

        $sceneTimestamps = self::detectSceneChanges($inputPath);
        if (empty($sceneTimestamps)) {
            return $inputPath;
        }

        $trimStart = self::findFirstActivity($sceneTimestamps);
        $segments = self::buildSegments($sceneTimestamps, $duration, $trimStart);

        if (empty($segments)) {
            return $inputPath;
        }

        $hasIdleSegments = collect($segments)->contains(fn (array $s): bool => $s['speed'] > 1);
        if (! $hasIdleSegments && $trimStart < 0.5) {
            return $inputPath;
        }

        $outputPath = self::generateOutputPath($inputPath);

        $success = self::buildAndRunFfmpeg($inputPath, $outputPath, $segments);

        if ($success && file_exists($outputPath) && filesize($outputPath) > 0) {
            $originalSize = filesize($inputPath);
            $processedSize = filesize($outputPath);

            Log::channel('yak')->info('Video processed', [
                'original_size' => $originalSize,
                'processed_size' => $processedSize,
                'trim_start' => round($trimStart, 2),
                'segments' => count($segments),
                'idle_segments' => collect($segments)->filter(fn (array $s): bool => $s['speed'] > 1)->count(),
            ]);

            // Replace original with processed version
            rename($outputPath, $inputPath);

            return $inputPath;
        }

        @unlink($outputPath);

        return $inputPath;
    }

    private static function getDuration(string $path): ?float
    {
        $result = Process::run(sprintf(
            'ffprobe -v quiet -show_entries format=duration -of csv=p=0 %s',
            escapeshellarg($path),
        ));

        if (! $result->successful()) {
            return null;
        }

        $duration = (float) trim($result->output());

        return $duration > 0 ? $duration : null;
    }

    /**
     * Detect scene changes and return timestamps where visual changes occur.
     *
     * @return list<float>
     */
    private static function detectSceneChanges(string $path): array
    {
        $result = Process::timeout(120)->run(sprintf(
            'ffprobe -v quiet -show_entries frame=pts_time -of csv=p=0 '
            . '-f lavfi "movie=%s,select=gt(scene\\,%s)"',
            str_replace("'", "'\\''", $path),
            self::SCENE_THRESHOLD,
        ));

        if (! $result->successful()) {
            Log::channel('yak')->warning('Scene detection failed', [
                'stderr' => substr($result->errorOutput(), 0, 500),
            ]);

            return [];
        }

        $timestamps = [];
        foreach (explode("\n", trim($result->output())) as $line) {
            $line = trim($line);
            if ($line !== '' && is_numeric($line)) {
                $timestamps[] = (float) $line;
            }
        }

        sort($timestamps);

        return $timestamps;
    }

    /**
     * Find the timestamp of the first meaningful visual activity.
     */
    private static function findFirstActivity(array $sceneTimestamps): float
    {
        if (empty($sceneTimestamps)) {
            return 0.0;
        }

        // Find the first cluster of scene changes (real activity, not a single flash)
        for ($i = 0; $i < count($sceneTimestamps); $i++) {
            $ts = $sceneTimestamps[$i];

            // Check if there's another change within a reasonable window
            if (isset($sceneTimestamps[$i + 1])
                && ($sceneTimestamps[$i + 1] - $ts) < self::MIN_START_ACTIVITY_SECONDS * 4) {
                // Start slightly before the first activity
                return max(0, $ts - 0.5);
            }
        }

        // Only isolated changes — start from the first one
        return max(0, $sceneTimestamps[0] - 0.5);
    }

    /**
     * Build segments with speed annotations.
     *
     * @param  list<float>  $sceneTimestamps
     * @return list<array{start: float, end: float, speed: int}>
     */
    private static function buildSegments(array $sceneTimestamps, float $duration, float $trimStart): array
    {
        $segments = [];
        $currentPos = $trimStart;

        // Group scene changes to find idle gaps
        $lastActivityEnd = $trimStart;

        foreach ($sceneTimestamps as $i => $ts) {
            if ($ts < $trimStart) {
                continue;
            }

            $gap = $ts - $lastActivityEnd;

            if ($gap > self::IDLE_THRESHOLD_SECONDS) {
                // Active segment before the gap
                if ($lastActivityEnd > $currentPos) {
                    $segments[] = [
                        'start' => $currentPos,
                        'end' => $lastActivityEnd,
                        'speed' => 1,
                    ];
                }

                // Idle segment (sped up)
                $segments[] = [
                    'start' => $lastActivityEnd,
                    'end' => $ts,
                    'speed' => self::IDLE_SPEED,
                ];

                $currentPos = $ts;
            }

            // Look ahead to find end of this activity cluster
            $clusterEnd = $ts + 1.0;
            for ($j = $i + 1; $j < count($sceneTimestamps); $j++) {
                if ($sceneTimestamps[$j] - $sceneTimestamps[$j - 1] < self::IDLE_THRESHOLD_SECONDS) {
                    $clusterEnd = $sceneTimestamps[$j] + 1.0;
                } else {
                    break;
                }
            }

            $lastActivityEnd = min($clusterEnd, $duration);
        }

        // Final segment to the end
        if ($currentPos < $duration) {
            $trailingGap = $duration - $lastActivityEnd;

            if ($trailingGap > self::IDLE_THRESHOLD_SECONDS) {
                // Active part
                if ($lastActivityEnd > $currentPos) {
                    $segments[] = [
                        'start' => $currentPos,
                        'end' => $lastActivityEnd,
                        'speed' => 1,
                    ];
                }
                // Speed up trailing idle
                $segments[] = [
                    'start' => $lastActivityEnd,
                    'end' => $duration,
                    'speed' => self::IDLE_SPEED,
                ];
            } else {
                $segments[] = [
                    'start' => $currentPos,
                    'end' => $duration,
                    'speed' => 1,
                ];
            }
        }

        return $segments;
    }

    /**
     * Build and run the ffmpeg command with speed changes and overlay text.
     *
     * @param  list<array{start: float, end: float, speed: int}>  $segments
     */
    private static function buildAndRunFfmpeg(string $inputPath, string $outputPath, array $segments): bool
    {
        // Build a complex filter that processes each segment
        $filterParts = [];
        $concatInputs = [];
        $segmentIndex = 0;

        foreach ($segments as $segment) {
            $start = $segment['start'];
            $end = $segment['end'];
            $speed = $segment['speed'];
            $segDuration = $end - $start;

            if ($segDuration < 0.1) {
                continue;
            }

            $label = "seg{$segmentIndex}";

            // Trim the segment
            $filterParts[] = sprintf(
                '[0:v]trim=start=%s:end=%s,setpts=PTS-STARTPTS[%sv_trimmed]',
                number_format($start, 3, '.', ''),
                number_format($end, 3, '.', ''),
                $label,
            );

            if ($speed > 1) {
                // Speed up video
                $ptsFactor = number_format(1.0 / $speed, 4, '.', '');
                $filterParts[] = sprintf(
                    '[%sv_trimmed]setpts=%s*PTS[%sv_fast]',
                    $label,
                    $ptsFactor,
                    $label,
                );

                // Add speed overlay text
                $filterParts[] = sprintf(
                    "[%sv_fast]drawtext=text='%dx':fontsize=28:fontcolor=white:borderw=2:bordercolor=black:"
                    . 'x=w-tw-20:y=20[%sv]',
                    $label,
                    $speed,
                    $label,
                );
            } else {
                $filterParts[] = sprintf('[%sv_trimmed]copy[%sv]', $label, $label);
            }

            $concatInputs[] = "[{$label}v]";
            $segmentIndex++;
        }

        if (empty($concatInputs)) {
            return false;
        }

        // Concat all segments
        $filterParts[] = sprintf(
            '%sconcat=n=%d:v=1:a=0[outv]',
            implode('', $concatInputs),
            count($concatInputs),
        );

        $filterComplex = implode(';', $filterParts);

        $command = sprintf(
            'ffmpeg -y -i %s -filter_complex %s -map "[outv]" -c:v libvpx-vp9 -crf 30 -b:v 0 '
            . '-deadline good -cpu-used 4 -an %s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($filterComplex),
            escapeshellarg($outputPath),
        );

        Log::channel('yak')->info('Running video processing', [
            'segments' => count($concatInputs),
            'filter_length' => strlen($filterComplex),
        ]);

        $result = Process::timeout(300)->run($command);

        if (! $result->successful()) {
            Log::channel('yak')->warning('Video processing failed', [
                'exit_code' => $result->exitCode(),
                'stderr' => substr($result->output(), -1000),
            ]);

            return false;
        }

        return true;
    }

    private static function generateOutputPath(string $inputPath): string
    {
        $dir = dirname($inputPath);
        $ext = pathinfo($inputPath, PATHINFO_EXTENSION);
        $name = pathinfo($inputPath, PATHINFO_FILENAME);

        return "{$dir}/{$name}_processed.{$ext}";
    }
}
