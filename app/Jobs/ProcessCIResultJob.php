<?php

namespace App\Jobs;

use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskLogger;
use App\Services\VideoProcessor;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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
        $this->cleanupBranch($repository);
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
        $this->cleanupBranch($repository);
    }

    /**
     * @return array<int, Artifact>
     */
    private function collectArtifacts(Repository $repository): array
    {
        $artifactsPath = $repository->path . '/.yak-artifacts';

        if (! File::isDirectory($artifactsPath)) {
            return [];
        }

        $files = File::files($artifactsPath);
        $artifacts = [];

        foreach ($files as $file) {
            $storagePath = "{$this->task->id}/{$file->getFilename()}";
            $type = $this->detectArtifactType($file->getExtension());

            Storage::disk('artifacts')->put(
                $storagePath,
                File::get($file->getPathname()),
            );

            // Post-process video walkthroughs (trim dead start, speed up idle sections)
            if ($type === 'video') {
                $fullPath = Storage::disk('artifacts')->path($storagePath);
                VideoProcessor::process($fullPath);
            }

            $artifacts[] = Artifact::create([
                'yak_task_id' => $this->task->id,
                'type' => $type,
                'filename' => $file->getFilename(),
                'disk_path' => $storagePath,
                'size_bytes' => Storage::disk('artifacts')->size($storagePath),
            ]);
        }

        return $artifacts;
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
        $result = Process::path($repository->path)
            ->run("git diff --stat {$repository->default_branch}...{$this->task->branch_name}");

        $output = trim($result->output());
        $lines = explode("\n", $output);
        $summary = end($lines);

        $added = 0;
        $removed = 0;

        if (preg_match('/(\d+)\s+insertions?\(\+\)/', $summary, $insertions)) {
            $added = (int) $insertions[1];
        }

        if (preg_match('/(\d+)\s+deletions?\(-\)/', $summary, $deletions)) {
            $removed = (int) $deletions[1];
        }

        return $added + $removed;
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
        app(LinearNotificationDriver::class)
            ->postIssueComment($this->task, $message);
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

    private function cleanupBranch(Repository $repository): void
    {
        GitOperations::cleanup($repository, $this->task->branch_name);
    }
}
