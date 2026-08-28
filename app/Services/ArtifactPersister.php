<?php

namespace App\Services;

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Turns files on the `artifacts` disk into Artifact DB rows.
 *
 * SandboxArtifactCollector pulls `/workspace/.yak-artifacts/` out of the
 * sandbox into `{task_id}/.yak-artifacts/` on the artifacts disk. This
 * service is the next step: walk that directory (top level plus the known
 * v3 subdirectories), dedup screenshots, create one Artifact row per
 * remaining file, and dispatch RenderVideoJob for videos so Remotion
 * post-processes them.
 *
 * Both ProcessCIResultJob (CI-gated path) and the "answered without code
 * changes" paths in RunYakJob/RetryYakJob call this so walkthroughs
 * captured by the agent are never orphaned on disk.
 */
class ArtifactPersister
{
    /**
     * Subdirectory name => artifact role (spec §8). A file's role inside
     * one of these directories comes from the directory, not the filename.
     *
     * @var array<string, string>
     */
    public const array SUBDIRECTORY_ROLES = [
        'shots' => 'shot',
        'stills' => 'still',
        'screenshots' => 'screenshot',
        'vo' => 'voiceover',
    ];

    /** Spec §8b: at most five screenshot artifacts survive one run. */
    private const int SCREENSHOT_CAP = 5;

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

        $manifest = self::readManifest($artifactsPath);
        $captions = self::captionsFrom($manifest);

        /** @var array<int, string> $screenshotHashes */
        $screenshotHashes = [];
        $screenshotCount = 0;
        $artifacts = [];

        foreach (self::orderedFiles($artifactsPath, $manifest) as [$subdir, $file]) {
            $artifact = self::persistFile(
                $task,
                $taskDir,
                $artifactsPath,
                $subdir,
                $file,
                $captions,
                $screenshotHashes,
                $screenshotCount,
            );

            if ($artifact !== null) {
                $artifacts[] = $artifact;
            }
        }

        if ($artifactsPath !== $taskDir) {
            File::deleteDirectory($artifactsPath);
        }

        return $artifacts;
    }

    /**
     * The v3 `manifest.json` decoded, or null when it is missing or not an
     * object/array.
     *
     * @return array<string, mixed>|null
     */
    private static function readManifest(string $dir): ?array
    {
        $path = $dir . '/manifest.json';

        if (! File::isFile($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Screenshot id => caption, from the manifest's `screenshots[]`.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<string, string>
     */
    private static function captionsFrom(?array $manifest): array
    {
        $captions = [];

        foreach (self::manifestScreenshots($manifest) as $entry) {
            $id = $entry['id'] ?? null;
            $caption = $entry['caption'] ?? null;

            if (is_string($id) && $id !== '' && is_string($caption) && $caption !== '') {
                $captions[$id] = $caption;
            }
        }

        return $captions;
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return array<int, array<string, mixed>>
     */
    private static function manifestScreenshots(?array $manifest): array
    {
        $screenshots = $manifest['screenshots'] ?? null;

        if (! is_array($screenshots)) {
            return [];
        }

        return array_values(array_filter($screenshots, is_array(...)));
    }

    /**
     * Every file to persist, paired with the subdirectory it came from
     * (null for the top level). Unknown subdirectories are skipped and
     * disappear with the directory delete.
     *
     * Only the screenshot order is load-bearing: spec §8b keeps the five
     * survivors of the cap in script order, so `screenshots/` runs in the
     * manifest's declared order, then whatever `screenshots/` holds that
     * the manifest does not name, and only then any legacy top-level
     * screenshot. A stray `description.png` at the top level must never
     * take a cap slot from a shot the agent actually scripted.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<int, array{0: string|null, 1: SplFileInfo}>
     */
    private static function orderedFiles(string $dir, ?array $manifest): array
    {
        $ordered = [];
        $topLevelScreenshots = [];

        foreach (File::files($dir) as $file) {
            if (self::detectArtifactType($file->getExtension()) === 'screenshot') {
                $topLevelScreenshots[] = [null, $file];

                continue;
            }

            $ordered[] = [null, $file];
        }

        foreach (self::orderedScreenshots($dir, $manifest) as $file) {
            $ordered[] = ['screenshots', $file];
        }

        $ordered = array_merge($ordered, $topLevelScreenshots);

        foreach (['shots', 'stills', 'vo'] as $subdir) {
            $path = $dir . '/' . $subdir;

            if (! File::isDirectory($path)) {
                continue;
            }

            foreach (File::files($path) as $file) {
                $ordered[] = [$subdir, $file];
            }
        }

        return $ordered;
    }

    /**
     * Files in `screenshots/`, sorted so those named by the manifest come
     * first in its order (matched on basename without extension against
     * `id`), with anything unlisted appended in directory order.
     *
     * @param  array<string, mixed>|null  $manifest
     * @return array<int, SplFileInfo>
     */
    private static function orderedScreenshots(string $dir, ?array $manifest): array
    {
        $path = $dir . '/screenshots';

        if (! File::isDirectory($path)) {
            return [];
        }

        /** @var array<string, SplFileInfo> $remaining */
        $remaining = [];
        foreach (File::files($path) as $file) {
            $remaining[pathinfo($file->getFilename(), PATHINFO_FILENAME)] = $file;
        }

        $ordered = [];

        foreach (self::manifestScreenshots($manifest) as $entry) {
            $id = $entry['id'] ?? null;

            if (is_string($id) && isset($remaining[$id])) {
                $ordered[] = $remaining[$id];
                unset($remaining[$id]);
            }
        }

        return array_merge($ordered, array_values($remaining));
    }

    /**
     * Move one file into its final place and write its Artifact row.
     * Returns null when the file is dropped (duplicate screenshot, or one
     * over the per-run screenshot cap).
     *
     * @param  array<string, string>  $captions
     * @param  array<int, string>  $screenshotHashes
     */
    private static function persistFile(
        YakTask $task,
        string $taskDir,
        string $artifactsPath,
        ?string $subdir,
        SplFileInfo $file,
        array $captions,
        array &$screenshotHashes,
        int &$screenshotCount,
    ): ?Artifact {
        $name = $file->getFilename();
        $storagePath = $subdir === null ? "{$task->id}/{$name}" : "{$task->id}/{$subdir}/{$name}";
        $type = self::detectArtifactType($file->getExtension());
        $role = $subdir === null ? Artifact::roleFor($type, $name) : self::SUBDIRECTORY_ROLES[$subdir];

        if ($artifactsPath !== $taskDir) {
            $targetPath = Storage::disk('artifacts')->path($storagePath);
            if ($file->getPathname() !== $targetPath) {
                File::ensureDirectoryExists(dirname($targetPath));
                File::move($file->getPathname(), $targetPath);
            }
        }

        $fullPath = Storage::disk('artifacts')->path($storagePath);

        $dhash = null;
        $caption = null;

        if ($role === 'screenshot') {
            $dhash = PerceptualHash::dhash($fullPath);

            if ($dhash !== null && self::isDuplicateScreenshot($task, $dhash, $screenshotHashes)) {
                TaskLogger::info($task, 'Dropped duplicate screenshot', [
                    'filename' => $name,
                    'dhash' => $dhash,
                ]);
                File::delete($fullPath);

                return null;
            }

            if ($screenshotCount >= self::SCREENSHOT_CAP) {
                TaskLogger::info($task, 'Dropped screenshot over the per-run cap', [
                    'filename' => $name,
                    'cap' => self::SCREENSHOT_CAP,
                ]);
                File::delete($fullPath);

                return null;
            }

            if ($dhash !== null) {
                $screenshotHashes[] = $dhash;
            }

            $screenshotCount++;
            $caption = $captions[pathinfo($name, PATHINFO_FILENAME)] ?? null;
        }

        $artifact = Artifact::create([
            'yak_task_id' => $task->id,
            'type' => $type,
            'role' => $role,
            'filename' => $name,
            'disk_path' => $storagePath,
            'size_bytes' => Storage::disk('artifacts')->size($storagePath),
            'dhash' => $dhash,
            'caption' => $caption,
        ]);

        if ($type === 'video') {
            RenderVideoJob::dispatch($artifact->id);

            // Legacy fallback for pre-storyboard repos: if no storyboard
            // exists, RenderVideoJob no-ops, so run in-place post-processing.
            $storyboardPath = Storage::disk('artifacts')->path("{$task->id}/storyboard.json");
            if (! file_exists($storyboardPath)) {
                VideoProcessor::process($fullPath);
            }
        }

        return $artifact;
    }

    /**
     * Stills carry `type = screenshot` too, so the cross-run lookup is
     * narrowed to rows that are actually screenshots — a still frame must
     * never suppress a later screenshot. Legacy rows written before `role`
     * existed have a null role and are still considered.
     *
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
            ->where(fn (Builder $query) => $query->whereNull('role')->orWhere('role', 'screenshot'))
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
