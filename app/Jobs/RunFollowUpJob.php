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

class RunFollowUpJob implements ShouldQueue
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
            $this->runFollowUp($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runFollowUp(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;

        $this->task->update(['status' => TaskStatus::Running]);
        TaskLogger::info($this->task, 'Picked up by worker — follow-up');

        if ($this->task->branch_name === null) {
            $this->handleError('Follow-up task has no branch to push to.');

            return;
        }

        try {
            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created for follow-up', ['container' => $containerName]);

            $branchName = $this->task->branch_name;
            $this->prepareExistingBranch($sandbox, $containerName, $repository, $branchName);

            $sandbox->pushSessionTranscript($containerName, $this->task->session_id);

            $result = $this->runAgentWithStaleSessionFallback($agent, new AgentRunRequest(
                prompt: YakPromptBuilder::followUpPrompt((string) $this->task->description),
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
            Log::error('RunFollowUpJob auth failure', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunFollowUpJob failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());
        } finally {
            if ($containerName !== null) {
                $sandbox->pullSessionTranscript($containerName, $this->task->session_id);
                $sandbox->pullClaudeCredentials($containerName);
                $sandbox->destroy($containerName);
            }
        }
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        TaskMetricsAccumulator::applyAccumulated($this->task, $result);

        $update = [
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ];

        if ($repository->ci_system !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
        }

        $this->task->update($update);

        DailyCost::accumulate($result->costUsd);

        $branchName = $this->task->branch_name;

        if ($branchName === null) {
            throw new \RuntimeException('Follow-up reached the push step with no branch name.');
        }

        $this->pushExistingBranch($sandbox, $containerName, $repository, $branchName);
        TaskLogger::info($this->task, 'Follow-up pushed', ['branch' => $branchName]);

        if ($repository->ci_system === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed your changes on branch {$branchName} — waiting for CI before updating the PR.");
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

        TaskLogger::error($this->task, 'Follow-up failed', ['error' => $errorMessage]);
    }
}
