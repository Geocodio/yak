<?php

namespace App\Jobs;

use App\DataTransferObjects\WalkthroughTimeline;
use App\Enums\NotificationType;
use App\Jobs\Concerns\RendersWalkthroughs;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\Mp4ChapterWriter;
use App\Services\PreviewGifGenerator;
use App\Services\PullRequestBodyUpdater;
use App\Services\RenderQaCheck;
use App\Services\RenderQaFailure;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use App\Services\WalkthroughPrSection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders one v3 walkthrough for one task (spec §7-8).
 *
 * Keyed on the task rather than on an artifact, because a v3 run produces
 * one cut from many clips: the job loads `script`, `manifest`, `shot` and
 * `voiceover` artifacts by role. The legacy single-webm pipeline keeps
 * using RenderVideoJob until no v2 artifacts remain.
 */
class RenderWalkthroughJob implements ShouldQueue
{
    use Queueable;
    use RendersWalkthroughs;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public int $taskId)
    {
        $this->onQueue('yak-render');
    }

    /**
     * Timeline first, chapters.json before the render, then the cut, the
     * QA gate, the poster, the preview GIF and the PR section. Every step
     * after the gate is best-effort: a missing GIF or thumbnail costs the
     * reviewer a nicety, not the walkthrough.
     */
    public function handle(
        VideoRenderer $renderer,
        RenderQaCheck $qa,
        PreviewGifGenerator $gif,
        Mp4ChapterWriter $chapters,
    ): void {
        $task = YakTask::find($this->taskId);

        if ($task === null) {
            Log::channel('yak')->info('RenderWalkthroughJob: task is gone', ['task_id' => $this->taskId]);

            return;
        }

        $script = $task->artifacts()->role('script')->latest('id')->first();
        $manifest = $task->artifacts()->role('manifest')->latest('id')->first();

        if ($script === null || $manifest === null) {
            Log::channel('yak')->info('RenderWalkthroughJob: no script or manifest artifact to render from', [
                'task_id' => $task->id,
            ]);

            return;
        }

        $disk = Storage::disk('artifacts');
        $taskDir = (string) $task->id;
        $scriptPath = $disk->path((string) $script->disk_path);
        $manifestPath = $disk->path((string) $manifest->disk_path);

        $clipPaths = [];
        foreach ($task->artifacts()->role('shot')->get() as $shot) {
            $clipPaths[pathinfo((string) $shot->filename, PATHINFO_FILENAME)] = $disk->path((string) $shot->disk_path);
        }

        $voiceover = $this->collectVoiceover($task, $renderer);
        $voiceoverJsonPath = $voiceover === null ? null : $this->writeVoiceoverJson($voiceover);

        $outputFilename = 'walkthrough.mp4';
        $outputDiskPath = "{$taskDir}/{$outputFilename}";
        $outputPath = $disk->path($outputDiskPath);

        $startedAt = hrtime(true);

        try {
            $timeline = $renderer->timeline($scriptPath, $manifestPath, $voiceoverJsonPath);

            $this->writeChapters($task, $timeline);
            $this->assertRenderable($timeline);

            File::ensureDirectoryExists(dirname($outputPath));

            $renderer->renderWalkthrough(
                scriptPath: $scriptPath,
                manifestPath: $manifestPath,
                clipPaths: $clipPaths,
                voiceover: $voiceover,
                theme: (array) config('yak.video.theme'),
                publicOrigin: $task->repository?->public_site_url,
                outputPath: $outputPath,
            );

            $qa->assertPasses($outputPath, $timeline);

            $thumbnailArtifact = $this->renderThumbnail($task, $outputPath, $taskDir);
            $previewArtifact = $this->renderPreviewGif($task, $gif, $outputPath, $taskDir, $timeline);

            $chapters->write($outputPath, $timeline);

            $cutArtifact = $this->replaceArtifact($task, 'cut', [
                'type' => 'video_cut',
                'filename' => $outputFilename,
                'disk_path' => $outputDiskPath,
            ]);

            $durationSeconds = $renderer->probeDurationSeconds($outputPath) ?? $timeline->durationSeconds;

            VideoMetric::create([
                'yak_task_id' => $task->id,
                'artifact_id' => $cutArtifact->id,
                'status' => VideoMetric::STATUS_RENDERED,
                'render_ms' => $this->elapsedMs($startedAt),
                'output_bytes' => $cutArtifact->size_bytes,
                'duration_seconds' => $durationSeconds,
            ]);

            $this->publishWalkthrough($task, $cutArtifact, $thumbnailArtifact, $previewArtifact, $durationSeconds);
        } catch (Throwable $e) {
            VideoMetric::create([
                'yak_task_id' => $task->id,
                'status' => VideoMetric::STATUS_FAILED,
                'render_ms' => $this->elapsedMs($startedAt),
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::channel('yak')->warning('RenderWalkthroughJob: walkthrough render failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($voiceoverJsonPath !== null) {
                @unlink($voiceoverJsonPath);
            }
        }
    }

    /**
     * The clip-level narration the composition mixes under the cut. Null
     * when the run recorded none, which is the shape `renderWalkthrough()`
     * and `timeline.ts` both read as "no voiceover".
     *
     * @return array<string, array{file: string, durationSeconds: float}>|null
     */
    private function collectVoiceover(YakTask $task, VideoRenderer $renderer): ?array
    {
        $disk = Storage::disk('artifacts');
        $voiceover = [];

        foreach ($task->artifacts()->role('voiceover')->get() as $clip) {
            $path = $disk->path((string) $clip->disk_path);

            $voiceover[pathinfo((string) $clip->filename, PATHINFO_FILENAME)] = [
                'file' => $path,
                'durationSeconds' => $renderer->probeDurationSeconds($path) ?? 0.0,
            ];
        }

        return $voiceover === [] ? null : $voiceover;
    }

    /**
     * `timeline.ts` takes the voiceover as a file rather than as an
     * argument, so the same array both sides read is written to a temp
     * file the `finally` block removes.
     *
     * @param  array<string, array{file: string, durationSeconds: float}>  $voiceover
     */
    private function writeVoiceoverJson(array $voiceover): string
    {
        $path = sys_get_temp_dir() . '/yak-voiceover-' . bin2hex(random_bytes(6)) . '.json';

        File::put($path, (string) json_encode($voiceover, JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * chapters.json is written before the render, not after it: a cut the
     * gate rejects still leaves the chapter data behind for the task page
     * and for whoever re-runs the render.
     */
    private function writeChapters(YakTask $task, WalkthroughTimeline $timeline): void
    {
        $diskPath = "{$task->id}/chapters.json";
        $path = Storage::disk('artifacts')->path($diskPath);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($timeline->chapters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->replaceArtifact($task, 'chapters', [
            'type' => 'file',
            'filename' => 'chapters.json',
            'disk_path' => $diskPath,
        ]);
    }

    /**
     * The pre-render gate. A caption that spills its box or a cut outside
     * the duration bounds fails the same way after rendering, so it is
     * rejected here rather than after a render nobody will watch. The
     * wording comes from RenderQaCheck so the two gates cannot drift.
     *
     * @throws RenderQaFailure
     */
    private function assertRenderable(WalkthroughTimeline $timeline): void
    {
        $reason = RenderQaCheck::overflowReason($timeline->captionOverflow)
            ?? RenderQaCheck::boundsReason($timeline->durationSeconds);

        if ($reason !== null) {
            throw new RenderQaFailure($reason);
        }
    }

    /**
     * Poster frame for the PR body and the task page. Failures are logged
     * and swallowed: the mp4 is still linked by duration.
     */
    private function renderThumbnail(YakTask $task, string $videoPath, string $taskDir): ?Artifact
    {
        $filename = 'walkthrough-thumbnail.jpg';
        $diskPath = "{$taskDir}/{$filename}";

        try {
            app(VideoThumbnailer::class)->generate($videoPath, Storage::disk('artifacts')->path($diskPath));
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RenderWalkthroughJob: thumbnail generation failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->replaceArtifact($task, 'thumbnail', [
            'type' => 'video_thumbnail',
            'filename' => $filename,
            'disk_path' => $diskPath,
        ]);
    }

    /**
     * The looping preview GitHub renders inline. Failures are logged and
     * swallowed; the PR body falls back to the poster thumbnail (spec §8).
     */
    private function renderPreviewGif(
        YakTask $task,
        PreviewGifGenerator $gif,
        string $videoPath,
        string $taskDir,
        WalkthroughTimeline $timeline,
    ): ?Artifact {
        $filename = 'walkthrough-preview.gif';
        $diskPath = "{$taskDir}/{$filename}";

        try {
            $gif->generate(
                $videoPath,
                Storage::disk('artifacts')->path($diskPath),
                $timeline->firstShotStartSeconds(),
            );
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RenderWalkthroughJob: preview gif generation failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->replaceArtifact($task, 'preview', [
            'type' => 'video_preview',
            'filename' => $filename,
            'disk_path' => $diskPath,
        ]);
    }

    /**
     * One row per role per task: a re-render replaces the artifact it
     * supersedes instead of stacking a second one beside it. The file is
     * only deleted when the replacement lives somewhere else — the new
     * output is already written by the time this runs.
     *
     * @param  array{type: string, filename: string, disk_path: string}  $attributes
     */
    private function replaceArtifact(YakTask $task, string $role, array $attributes): Artifact
    {
        $disk = Storage::disk('artifacts');

        foreach ($task->artifacts()->role($role)->get() as $existing) {
            Log::channel('yak')->info('RenderWalkthroughJob: replacing superseded artifact', [
                'task_id' => $task->id,
                'role' => $role,
                'artifact_id' => $existing->id,
            ]);

            if ((string) $existing->disk_path !== $attributes['disk_path']) {
                $disk->delete((string) $existing->disk_path);
            }

            $existing->delete();
        }

        $path = $disk->path($attributes['disk_path']);

        return Artifact::create([
            'yak_task_id' => $task->id,
            'role' => $role,
            'size_bytes' => file_exists($path) ? filesize($path) : 0,
            ...$attributes,
        ]);
    }

    /**
     * Hand the finished walkthrough to the PR body. A GitHub failure is
     * logged and swallowed — the render succeeded, and retrying the whole
     * job would re-render a cut that is already on disk.
     */
    private function publishWalkthrough(
        YakTask $task,
        Artifact $cutArtifact,
        ?Artifact $thumbnailArtifact,
        ?Artifact $previewArtifact,
        float $durationSeconds,
    ): void {
        $prNumber = $this->extractPrNumber($task->pr_url);

        if ($prNumber === null || $task->repo === '') {
            return;
        }

        try {
            app(PullRequestBodyUpdater::class)->setWalkthrough(
                repoFullName: Repository::githubNameFor((string) $task->repo),
                prNumber: $prNumber,
                walkthroughUrl: $cutArtifact->signedUrl(),
                filename: (string) $cutArtifact->filename,
                thumbnailUrl: $thumbnailArtifact?->publicUrl(),
                gifUrl: $previewArtifact?->publicUrl(),
                durationSeconds: $durationSeconds,
                chapters: WalkthroughPrSection::chaptersForTask($task),
            );
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RenderWalkthroughJob: failed to publish walkthrough to PR body', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retries are exhausted. The failed metric row is already written by
     * `handle()`, so this only tells the humans: the task's notification
     * channel gets an Error, and the PR's walkthrough section is replaced
     * so it never keeps a stale "rendering" placeholder. Nothing throws.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('yak')->error('RenderWalkthroughJob: render failed after retries', [
            'task_id' => $this->taskId,
            'error' => $e->getMessage(),
        ]);

        $task = YakTask::find($this->taskId);

        if ($task === null) {
            return;
        }

        $reason = mb_substr($e->getMessage(), 0, 300);

        try {
            SendNotificationJob::dispatch(
                $task,
                NotificationType::Error,
                "The walkthrough video for this task could not be rendered ({$reason}). The PR is unaffected; once the cause is fixed, re-run it with `php artisan yak:video:rerender --task={$task->id}`.",
            );
        } catch (Throwable $notifyError) {
            Log::channel('yak')->warning('RenderWalkthroughJob: failed to queue failure notification', [
                'task_id' => $task->id,
                'error' => $notifyError->getMessage(),
            ]);
        }

        $prNumber = $this->extractPrNumber($task->pr_url);

        if ($prNumber === null || $task->repo === '') {
            return;
        }

        try {
            app(PullRequestBodyUpdater::class)->setWalkthroughUnavailable(
                repoFullName: Repository::githubNameFor((string) $task->repo),
                prNumber: $prNumber,
                reason: $reason,
            );
        } catch (Throwable $patchError) {
            Log::channel('yak')->warning('RenderWalkthroughJob: failed to mark walkthrough unavailable on PR', [
                'task_id' => $task->id,
                'error' => $patchError->getMessage(),
            ]);
        }
    }
}
