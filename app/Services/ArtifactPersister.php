<?php

namespace App\Services;

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Turns files on the `artifacts` disk into Artifact DB rows.
 *
 * SandboxArtifactCollector pulls `/workspace/.yak-artifacts/` out of the
 * sandbox into `{task_id}/.yak-artifacts/` on the artifacts disk. This
 * service is the next step: flatten that subdirectory, dedup screenshots,
 * create one Artifact row per remaining file, and dispatch RenderVideoJob
 * for videos so Remotion post-processes them.
 *
 * Both ProcessCIResultJob (CI-gated path) and the "answered without code
 * changes" paths in RunYakJob/RetryYakJob call this so walkthroughs
 * captured by the agent are never orphaned on disk.
 */
class ArtifactPersister
{
    /**
     * @return array<int, Artifact>
     */
    public static function persist(YakTask $task): array
    {
        $taskDir = Storage::disk('artifacts')->path((string) $task->id);

        $artifactsPath = is_dir($taskDir . '/.yak-artifacts')
            ? $taskDir . '/.yak-artifacts'
            : $taskDir;

        if (! File::isDirectory($artifactsPath)) {
            return [];
        }

        $files = File::files($artifactsPath);
        $artifacts = [];
        $screenshotHashes = [];

        foreach ($files as $file) {
            $storagePath = "{$task->id}/{$file->getFilename()}";
            $type = self::detectArtifactType($file->getExtension());

            if ($artifactsPath !== $taskDir) {
                $targetPath = Storage::disk('artifacts')->path($storagePath);
                if ($file->getPathname() !== $targetPath) {
                    File::move($file->getPathname(), $targetPath);
                }
            }

            $fullPath = Storage::disk('artifacts')->path($storagePath);

            $dhash = null;
            if ($type === 'screenshot') {
                $dhash = PerceptualHash::dhash($fullPath);
                if ($dhash !== null && self::isDuplicateScreenshot($task, $dhash, $screenshotHashes)) {
                    TaskLogger::info($task, 'Dropped duplicate screenshot', [
                        'filename' => $file->getFilename(),
                        'dhash' => $dhash,
                    ]);
                    File::delete($fullPath);

                    continue;
                }
                if ($dhash !== null) {
                    $screenshotHashes[] = $dhash;
                }
            }

            $artifact = Artifact::create([
                'yak_task_id' => $task->id,
                'type' => $type,
                'filename' => $file->getFilename(),
                'disk_path' => $storagePath,
                'size_bytes' => Storage::disk('artifacts')->size($storagePath),
                'dhash' => $dhash,
            ]);

            $artifacts[] = $artifact;

            if ($type === 'video') {
                RenderVideoJob::dispatch($artifact->id);

                // Legacy fallback for pre-storyboard repos: if no storyboard
                // exists, RenderVideoJob no-ops, so run in-place post-processing.
                $storyboardPath = Storage::disk('artifacts')->path("{$task->id}/storyboard.json");
                if (! file_exists($storyboardPath)) {
                    VideoProcessor::process($fullPath);
                }
            }
        }

        if ($artifactsPath !== $taskDir) {
            File::deleteDirectory($artifactsPath);
        }

        return $artifacts;
    }

    /**
     * @param  array<int, string>  $knownHashes
     */
    private static function isDuplicateScreenshot(YakTask $task, string $dhash, array $knownHashes): bool
    {
        foreach ($knownHashes as $known) {
            if (PerceptualHash::hamming($dhash, $known) <= 2) {
                return true;
            }
        }

        return Artifact::where('yak_task_id', $task->id)
            ->where('type', 'screenshot')
            ->whereNotNull('dhash')
            ->pluck('dhash')
            ->contains(fn (string $known) => PerceptualHash::hamming($dhash, $known) <= 2);
    }

    private static function detectArtifactType(string $extension): string
    {
        return match (strtolower($extension)) {
            'png', 'jpg', 'jpeg', 'gif', 'webp' => 'screenshot',
            'mp4', 'webm' => 'video',
            'html' => 'research',
            default => 'file',
        };
    }
}
