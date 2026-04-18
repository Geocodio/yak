<?php

namespace App\Jobs;

use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\PullRequestBodyUpdater;
use App\Services\VideoRenderer;
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

    public function __construct(public int $rawVideoArtifactId, public string $tier = 'reviewer') {}

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
            $this->publishDirectorCut($raw->task, $cutArtifact);
        }
    }

    /**
     * Patch the PR body with the Director's Cut link and flip the task's
     * director_cut_status to 'ready'. Failures patching GitHub are logged
     * but not re-thrown: the render itself succeeded, so we don't want to
     * retry the whole RenderVideoJob just because GitHub rejected a PATCH.
     */
    private function publishDirectorCut(?YakTask $task, Artifact $cutArtifact): void
    {
        if ($task === null) {
            return;
        }

        $prNumber = $this->extractPrNumber($task->pr_url);

        if ($prNumber !== null && $task->repo !== null && $task->repo !== '') {
            try {
                app(PullRequestBodyUpdater::class)->appendDirectorCut(
                    repoFullName: $task->repo,
                    prNumber: $prNumber,
                    directorCutUrl: $cutArtifact->signedUrl(),
                );
            } catch (Throwable $e) {
                Log::channel('yak')->warning('RenderVideoJob: failed to append Director\'s Cut to PR body', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $task->update(['director_cut_status' => 'ready']);
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
