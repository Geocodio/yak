<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\RetriesWithoutStaleSession;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\Middleware\PreventBranchOverlap;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\IncusSandboxManager;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ClarificationReplyJob implements ShouldQueue
{
    use HandlesAgentJobFailure;
    use Queueable;
    use ResumesAgentOnExistingBranch;
    use RetriesWithoutStaleSession;

    public int $timeout = 3600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly string $replyText,
    ) {
        $this->onQueue('yak-claude');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new PreventBranchOverlap($this->task),
            new PausesDuringDrain,
            new EnsureRepoReady,
            new EnsureDailyBudget,
        ];
    }

    public function handle(AgentRunner $agent): void
    {
        TaskContext::set($this->task);

        try {
            $this->runReply($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runReply(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;

        $this->task->update([
            'status' => TaskStatus::Running,
        ]);

        TaskLogger::info($this->task, 'Picked up by worker — clarification reply');

        try {
            // Create sandbox from repo snapshot
            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created for clarification reply', ['container' => $containerName]);

            // Configure git and checkout the task branch
            $this->prepareBranch($sandbox, $containerName, $repository);

            $sandbox->pushSessionTranscript($containerName, $this->task->session_id);

            $result = $this->runAgentWithStaleSessionFallback($agent, new AgentRunRequest(
                prompt: YakPromptBuilder::clarificationReplyPrompt($this->replyText),
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: $this->task->session_id,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
            ));

            if ($result->isError) {
                $this->handleError($result->failureMessage());

                return;
            }

            SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);
            ArtifactPersister::persist($this->task);

            $this->handleSuccess($repository, $result, $sandbox, $containerName);
        } catch (ClaudeAuthException $e) {
            Log::error('ClarificationReplyJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('ClarificationReplyJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
        } finally {
            if ($containerName !== null) {
                $sandbox->pullSessionTranscript($containerName, $this->task->session_id);
                $sandbox->pullClaudeCredentials($containerName);
                $sandbox->destroy($containerName);
            }
        }
    }

    private function prepareBranch(IncusSandboxManager $sandbox, string $containerName, Repository $repository): void
    {
        $branchName = $this->task->branch_name ?? 'yak/' . $this->task->external_id;

        $this->prepareExistingBranch($sandbox, $containerName, $repository, $branchName);
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        TaskMetricsAccumulator::applyAccumulated($this->task, $result);

        $update = [
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ];

        // Park at AwaitingCi only when there's actually CI to wait for. For
        // ci_system=none the next step is PR creation via ProcessCIResultJob,
        // so keep the task in Running until that finalizes.
        if ($repository->ci_system !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
        }

        $this->task->update($update);

        DailyCost::accumulate($result->costUsd);

        if ($this->task->branch_name !== null) {
            $this->pushExistingBranch($sandbox, $containerName, $repository, $this->task->branch_name);

            TaskLogger::info($this->task, 'Fix pushed', ['branch' => $this->task->branch_name]);
        }

        if ($repository->ci_system === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed updated fix on branch {$this->task->branch_name} — waiting for CI to finish before opening a PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    private function handleError(string $errorMessage): void
    {
        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);
    }
}
