<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Drivers\LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Models\Artifact;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResearchYakJob implements ShouldQueue
{
    use HandlesAgentJobFailure;
    use Queueable;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public YakTask $task,
    ) {
        $this->onQueue('yak-claude');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new EnsureRepoReady,
            new EnsureDailyBudget,
        ];
    }

    public function handle(AgentRunner $agent): void
    {
        TaskContext::set($this->task);

        try {
            $this->runResearch($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runResearch(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        TaskLogger::info($this->task, 'Picked up by worker — research');

        // One-shot "starting research" progress on first attempt,
        // matching the RunYakJob cadence. Research tasks can take
        // minutes; this keeps the channel alive while we explore.
        if ((int) $this->task->attempts === 1 && (bool) config('yak.emit_start_progress', true)) {
            SendNotificationJob::dispatch(
                $this->task,
                NotificationType::Progress,
                "Diving into `{$this->task->repo}` — researching now, no code changes.",
            );
        }

        try {
            // Create sandbox from repo snapshot
            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created for research', ['container' => $containerName]);

            // Ensure we're on the default branch with latest code
            $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');
            $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$repository->default_branch}", timeout: 30);

            $result = $agent->run(new AgentRunRequest(
                prompt: YakPromptBuilder::taskPrompt($this->task),
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
            ));

            if ($result->isError) {
                $this->handleError($result->resultSummary ?: 'Agent returned an error or malformed output');

                return;
            }

            $this->handleSuccess($repository, $result, $sandbox, $containerName);
        } catch (ClaudeAuthException $e) {
            Log::error('ResearchYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('ResearchYakJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
        } finally {
            if ($containerName !== null) {
                $sandbox->destroy($containerName);
            }
        }
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        $summary = $result->resultSummary;

        $artifact = $this->collectHtmlArtifact($sandbox, $containerName);
        $artifactUrl = $artifact !== null ? $this->generateSignedUrl($artifact) : null;

        TaskMetricsAccumulator::applyFresh($this->task, $result);

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $summary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        DailyCost::accumulate($result->costUsd);

        TaskLogger::info($this->task, 'Task completed');

        $context = $artifactUrl !== null
            ? "Research complete: {$summary}\n\nFindings: {$artifactUrl}"
            : "Research complete: {$summary}";

        $notificationMessage = YakPersonality::generate(NotificationType::Result, $context);
        $this->postToSource($notificationMessage);

        if ($this->task->source === 'linear') {
            $this->moveLinearToDone();
        }
    }

    private function collectHtmlArtifact(IncusSandboxManager $sandbox, string $containerName): ?Artifact
    {
        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');
        $remotePath = "{$workspacePath}/.yak-artifacts/research.html";

        if (! $sandbox->fileExists($containerName, $remotePath)) {
            return null;
        }

        // Pull the artifact from the sandbox to local storage
        $storagePath = "{$this->task->id}/research.html";
        $localPath = Storage::disk('artifacts')->path($storagePath);

        $localDir = dirname($localPath);
        if (! is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        $sandbox->pullFile($containerName, $remotePath, $localPath);

        return Artifact::create([
            'yak_task_id' => $this->task->id,
            'type' => 'research',
            'filename' => 'research.html',
            'disk_path' => $storagePath,
            'size_bytes' => filesize($localPath) ?: 0,
        ]);
    }

    private function generateSignedUrl(Artifact $artifact): string
    {
        $expires = now()->addDays(7)->timestamp;
        $payload = "{$artifact->id}:{$expires}";
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return url("/artifacts/{$artifact->id}?expires={$expires}&signature={$signature}");
    }

    private function handleError(string $errorMessage): void
    {
        // Don't downgrade a user-cancelled task back to Failed.
        if ($this->task->fresh()?->status === TaskStatus::Cancelled) {
            return;
        }

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);
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
            ->postAgentActivity($sessionId, type: 'response', body: $message);
    }

    private function moveLinearToDone(): void
    {
        $stateId = (string) config('yak.channels.linear.done_state_id');

        if ($stateId === '') {
            return;
        }

        app(LinearNotificationDriver::class)
            ->setIssueState($this->task, $stateId);
    }
}
