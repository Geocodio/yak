<?php

namespace App\Jobs;

use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\PullRequestBodyUpdater;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public int $rawVideoArtifactId, public string $tier = 'reviewer')
    {
        $this->onQueue('yak-render');
    }

    public function handle(VideoRenderer $renderer): void
    {
        $raw = Artifact::find($this->rawVideoArtifactId);
        if (! $raw || $raw->type !== 'video') {
            Log::info('RenderVideoJob: raw video artifact missing or wrong type', [
                'id' => $this->rawVideoArtifactId,
            ]);

            return;
        }

        $disk = Storage::disk('artifacts');
        $taskDir = dirname($raw->disk_path);
        $storyboardDiskPath = "{$taskDir}/storyboard.json";

        if (! $disk->exists($storyboardDiskPath)) {
            Log::info('RenderVideoJob: no storyboard.json, falling back to raw webm attachment', [
                'task' => $raw->yak_task_id,
            ]);

            return;
        }

        $webmPath = $disk->path($raw->disk_path);
        $storyboardPath = $disk->path($storyboardDiskPath);
        $outputFilename = $this->tier === 'director' ? 'director-cut.mp4' : 'reviewer-cut.mp4';
        $outputDiskPath = "{$taskDir}/{$outputFilename}";
        $outputPath = $disk->path($outputDiskPath);

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        try {
            $renderer->render(
                webmPath: $webmPath,
                storyboardPath: $storyboardPath,
                outputPath: $outputPath,
                tier: $this->tier,
            );
        } catch (Throwable $e) {
            Log::warning('RenderVideoJob: Remotion render failed', [
                'task' => $raw->yak_task_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $cutArtifact = Artifact::create([
            'yak_task_id' => $raw->yak_task_id,
            'type' => 'video_cut',
            'filename' => $outputFilename,
            'disk_path' => $outputDiskPath,
            'size_bytes' => file_exists($outputPath) ? filesize($outputPath) : 0,
        ]);

        if ($this->tier === 'director') {
            $raw->task?->update(['director_cut_status' => 'ready']);

            return;
        }

        $thumbnailArtifact = $this->renderThumbnail($raw, $outputPath, $taskDir);

        $this->publishReviewerCut($raw->task, $cutArtifact, $thumbnailArtifact);
    }

    /**
     * Extract a poster frame from the rendered mp4 with a play-button
     * overlay so GitHub can embed a clickable thumbnail in the PR body.
     * Failures are logged and swallowed — the mp4 itself is still linked
     * textually, so a broken thumbnail shouldn't fail the whole job.
     */
    private function renderThumbnail(Artifact $raw, string $videoPath, string $taskDir): ?Artifact
    {
        $thumbnailFilename = 'reviewer-cut-thumbnail.jpg';
        $thumbnailDiskPath = "{$taskDir}/{$thumbnailFilename}";
        $thumbnailPath = Storage::disk('artifacts')->path($thumbnailDiskPath);

        try {
            app(VideoThumbnailer::class)->generate($videoPath, $thumbnailPath);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RenderVideoJob: thumbnail generation failed', [
                'task_id' => $raw->yak_task_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return Artifact::create([
            'yak_task_id' => $raw->yak_task_id,
            'type' => 'video_thumbnail',
            'filename' => $thumbnailFilename,
            'disk_path' => $thumbnailDiskPath,
            'size_bytes' => file_exists($thumbnailPath) ? filesize($thumbnailPath) : 0,
        ]);
    }

    /**
     * Swap the PR body's raw-webm fallback link for the rendered reviewer
     * cut. If the PR was created while the render was still in flight, the
     * body points at the raw webm; this upgrades it to the polished mp4.
     * Failures patching GitHub are logged but not re-thrown — the render
     * itself succeeded, so we don't want to retry the whole job just
     * because GitHub rejected the PATCH.
     */
    private function publishReviewerCut(?YakTask $task, Artifact $cutArtifact, ?Artifact $thumbnailArtifact): void
    {
        if ($task === null) {
            return;
        }

        $prNumber = $this->extractPrNumber($task->pr_url);

        if ($prNumber === null || $task->repo === '') {
            return;
        }

        try {
            app(PullRequestBodyUpdater::class)->setReviewerCut(
                repoFullName: $task->repo,
                prNumber: $prNumber,
                reviewerCutUrl: $cutArtifact->signedUrl(),
                filename: $cutArtifact->filename,
                thumbnailUrl: $thumbnailArtifact?->signedUrl(),
            );
        } catch (Throwable $e) {
            Log::channel('yak')->warning('RenderVideoJob: failed to publish reviewer cut to PR body', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractPrNumber(?string $prUrl): ?int
    {
        if ($prUrl === null || $prUrl === '') {
            return null;
        }

        if (preg_match('#/pull/(\d+)#', $prUrl, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function failed(Throwable $e): void
    {
        Log::error('RenderVideoJob: render failed after retries', [
            'artifact' => $this->rawVideoArtifactId,
            'error' => $e->getMessage(),
        ]);
    }
}
