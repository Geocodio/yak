<?php

namespace App\Jobs;

use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use App\Services\PerceptualHash;
use App\Services\TaskLogger;
use App\Services\VideoProcessor;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCIResultJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly bool $passed,
        public readonly ?string $output = null,
    ) {
        $this->onQueue('default');
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('yak')->error(self::class . ' failed', [
            'task_id' => $this->task->id,
            'error' => $e?->getMessage() ?? 'Job failed without exception',
            'exception_class' => $e !== null ? get_class($e) : null,
        ]);
    }

    public function handle(): void
    {
        TaskContext::set($this->task);

        try {
            TaskLogger::info($this->task, 'CI result received', ['passed' => $this->passed]);

            if ($this->passed) {
                $this->handleGreenPath();
            } elseif ($this->task->attempts < (int) config('yak.max_attempts')) {
                $this->handleRetry();
            } else {
                $this->handleFinalFailure();
            }
        } finally {
            TaskContext::clear();
        }
    }

    private function handleGreenPath(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $this->collectArtifacts($repository);

        $loc = $this->countLinesOfCode($repository);
        $isLargeChange = $loc > (int) config('yak.large_change_threshold');

        CreatePullRequestJob::dispatchSync($this->task, $isLargeChange);

        $this->task->refresh();
        $prUrl = $this->task->pr_url ?? '';

        TaskLogger::info($this->task, 'PR created', ['pr_url' => $prUrl]);
        $message = YakPersonality::generate(NotificationType::Result, "PR created: {$prUrl}");
        $this->postToSource($message);

        if ($this->task->source === 'linear') {
            $this->moveLinearToInReview();
        }

        $this->task->update([
            'status' => TaskStatus::Success,
            'completed_at' => now(),
        ]);

        TaskLogger::info($this->task, 'Task completed');
    }

    private function handleRetry(): void
    {
        $message = YakPersonality::generate(NotificationType::Retry, 'CI failed, retrying');
        $this->postToSource($message);

        $this->task->update([
            'status' => TaskStatus::Retrying,
            'attempts' => $this->task->attempts + 1,
        ]);

        RetryYakJob::dispatch($this->task, $this->output);
    }

    private function handleFinalFailure(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $failureSummary = $this->output ?? 'CI failed after maximum attempts';
        $message = YakPersonality::generate(NotificationType::Error, "CI failed: {$failureSummary}");
        $this->postToSource($message);

        $this->task->update([
            'status' => TaskStatus::Failed,
            'completed_at' => now(),
            'error_log' => $failureSummary,
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $failureSummary]);
    }

    /**
     * Collect artifacts from the local artifacts disk.
     *
     * Artifacts are pre-collected from the sandbox by the agent jobs
     * (RunYakJob, RetryYakJob) via SandboxArtifactCollector before
     * the sandbox container is destroyed. By the time this job runs,
     * the files are already on the host at {task_id}/ on the artifacts disk.
     *
     * @return array<int, Artifact>
     */
    private function collectArtifacts(Repository $repository): array
    {
        $taskDir = Storage::disk('artifacts')->path((string) $this->task->id);

        // Check for artifacts collected from sandbox
        // They may be in a .yak-artifacts subdirectory (from pullDirectory)
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
            $storagePath = "{$this->task->id}/{$file->getFilename()}";
            $type = $this->detectArtifactType($file->getExtension());

            // If the file came from the .yak-artifacts subdirectory, move it up
            if ($artifactsPath !== $taskDir) {
                $targetPath = Storage::disk('artifacts')->path($storagePath);
                if ($file->getPathname() !== $targetPath) {
                    File::move($file->getPathname(), $targetPath);
                }
            }

            $fullPath = Storage::disk('artifacts')->path($storagePath);

            // Drop screenshots that are perceptually identical to one we've
            // already kept for this task. dHash distance ≤ 2 tolerates PNG
            // encoding noise without collapsing real UI state changes.
            $dhash = null;
            if ($type === 'screenshot') {
                $dhash = PerceptualHash::dhash($fullPath);
                if ($dhash !== null && $this->isDuplicateScreenshot($dhash, $screenshotHashes)) {
                    TaskLogger::info($this->task, 'Dropped duplicate screenshot', [
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

            // Post-process video walkthroughs (trim dead start, speed up idle sections)
            if ($type === 'video') {
                VideoProcessor::process($fullPath);
            }

            $artifacts[] = Artifact::create([
                'yak_task_id' => $this->task->id,
                'type' => $type,
                'filename' => $file->getFilename(),
                'disk_path' => $storagePath,
                'size_bytes' => Storage::disk('artifacts')->size($storagePath),
                'dhash' => $dhash,
            ]);
        }

        // Clean up the .yak-artifacts subdirectory if it exists
        if ($artifactsPath !== $taskDir) {
            File::deleteDirectory($artifactsPath);
        }

        return $artifacts;
    }

    /**
     * @param  array<int, string>  $knownHashes
     */
    private function isDuplicateScreenshot(string $dhash, array $knownHashes): bool
    {
        foreach ($knownHashes as $known) {
            if (PerceptualHash::hamming($dhash, $known) <= 2) {
                return true;
            }
        }

        return Artifact::where('yak_task_id', $this->task->id)
            ->where('type', 'screenshot')
            ->whereNotNull('dhash')
            ->pluck('dhash')
            ->contains(fn (string $known) => PerceptualHash::hamming($dhash, $known) <= 2);
    }

    private function detectArtifactType(string $extension): string
    {
        return match (strtolower($extension)) {
            'png', 'jpg', 'jpeg', 'gif', 'webp' => 'screenshot',
            'mp4', 'webm' => 'video',
            'html' => 'research',
            default => 'file',
        };
    }

    private function countLinesOfCode(Repository $repository): int
    {
        if ($this->task->branch_name === null) {
            return 0;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            return 0;
        }

        try {
            $compare = app(GitHubAppService::class)->compareBranches(
                $installationId,
                $repository->slug,
                $repository->default_branch,
                $this->task->branch_name,
            );

            return $compare['loc_changed'];
        } catch (\Throwable $e) {
            Log::warning('Failed to compute LOC via GitHub API', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function postToSource(string $message): void
    {
        match ($this->task->source) {
            'slack' => $this->postToSlack($message),
            'linear' => $this->postToLinear($message),
            default => null,
        };
    }

    private function postToSlack(string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $this->task->slack_channel) {
            return;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $this->task->slack_channel,
                'thread_ts' => $this->task->slack_thread_ts,
                'text' => $message,
            ]);
    }

    private function postToLinear(string $message): void
    {
        $sessionId = (string) $this->task->linear_agent_session_id;

        if ($sessionId === '') {
            return;
        }

        app(LinearNotificationDriver::class)
            ->postAgentActivity($sessionId, type: 'thought', body: $message);
    }

    private function moveLinearToInReview(): void
    {
        $stateId = (string) config('yak.channels.linear.in_review_state_id');

        if ($stateId === '') {
            return;
        }

        app(LinearNotificationDriver::class)
            ->setIssueState($this->task, $stateId);
    }
}
