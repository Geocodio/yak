<?php

namespace App\Jobs;

use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use App\Services\TaskLogger;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $errorMessage = $e?->getMessage() ?? 'Job failed without exception';

        Log::channel('yak')->error(self::class . ' failed', [
            'task_id' => $this->task->id,
            'error' => $errorMessage,
            'exception_class' => $e !== null ? get_class($e) : null,
        ]);

        $fresh = $this->task->fresh();

        // Don't disturb a task that's already settled (Success or Cancelled).
        // For anything else — typically AwaitingCi or Running when PR creation
        // throws — flip to Failed so the UI stops displaying a misleading
        // transient state and the user can retry.
        if ($fresh === null || $fresh->status->isFinal()) {
            return;
        }

        $fresh->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($fresh, 'Task failed during CI result processing', ['error' => $errorMessage]);

        try {
            $this->postToSource(YakPersonality::generate(
                NotificationType::Error,
                "Task failed while finalizing: {$errorMessage}",
            ));
        } catch (\Throwable $notifyError) {
            Log::channel('yak')->warning(self::class . ' failed() notification errored', [
                'task_id' => $this->task->id,
                'error' => $notifyError->getMessage(),
            ]);
        }
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

        // Artifacts are persisted upstream in RunYakJob/RetryYakJob/
        // ClarificationReplyJob right after SandboxArtifactCollector pulls
        // them out of the sandbox — that way the walkthrough video +
        // screenshots show up on the task page and Remotion rendering
        // runs in parallel with Drone CI instead of sequentially.

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
