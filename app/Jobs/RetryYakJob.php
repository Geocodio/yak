<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\GitOperations;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\GitHubAppService;
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

class RetryYakJob implements ShouldQueue
{
    use HandlesAgentJobFailure;
    use Queueable;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly ?string $failureOutput = null,
    ) {
        $this->onQueue('yak-claude');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new PausesDuringDrain,
            new EnsureRepoReady,
            new EnsureDailyBudget,
        ];
    }

    public function handle(AgentRunner $agent): void
    {
        TaskContext::set($this->task);

        try {
            $this->runRetry($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runRetry(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;

        TaskLogger::info($this->task, 'Picked up by worker — retry', ['attempt' => $this->task->attempts]);

        try {
            // Create sandbox from repo snapshot (has the setup environment ready)
            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created for retry', ['container' => $containerName]);

            // Configure git and checkout the task branch
            $this->prepareRetryBranch($sandbox, $containerName, $repository);

            $result = $agent->run(new AgentRunRequest(
                prompt: YakPromptBuilder::retryPrompt($this->task, $this->failureOutput),
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                // Can't --resume: the previous attempt's sandbox was
                // destroyed after push, so Claude's session file is
                // gone. The prompt above is self-contained instead.
                resumeSessionId: null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
            ));

            if ($result->isError) {
                $this->handleError($result->failureMessage());

                return;
            }

            if ($result->clarificationNeeded) {
                $this->handleClarification($result);

                return;
            }

            // Collect artifacts before sandbox is destroyed
            SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);

            $this->handleSuccess($repository, $result, $sandbox, $containerName);
        } catch (ClaudeAuthException $e) {
            Log::error('RetryYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RetryYakJob failed', [
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

    private function prepareRetryBranch(IncusSandboxManager $sandbox, string $containerName, Repository $repository): void
    {
        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');

        // Configure git credentials
        $gitName = config('yak.git_user_name', 'Yak');
        $gitEmail = config('yak.git_user_email', 'yak@noreply.github.com');
        $sandbox->run($containerName, 'git config --global user.name ' . escapeshellarg($gitName), timeout: 10);
        $sandbox->run($containerName, 'git config --global user.email ' . escapeshellarg($gitEmail), timeout: 10);

        $installationId = (int) config('yak.channels.github.installation_id');
        if ($installationId) {
            $token = app(GitHubAppService::class)->getInstallationToken($installationId);
            $sandbox->run(
                $containerName,
                'git config --global credential.https://github.com.helper ' .
                escapeshellarg("!f() { echo \"protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\"; }; f"),
                timeout: 10,
            );
        }

        // Fetch the task branch and check it out
        $branchName = $this->task->branch_name ?? 'yak/' . $this->task->external_id;
        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$branchName}", timeout: 60);
        $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$branchName}", timeout: 30);
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        TaskMetricsAccumulator::applyAccumulated($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');

        if ($this->task->branch_name !== null) {
            // Safety check
            $branchResult = $sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10);
            $currentBranch = trim($branchResult->output());

            if ($currentBranch === $repository->default_branch) {
                throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
            }

            $hasCommits = GitOperations::hasNewCommits($sandbox, $containerName, $workspacePath, $repository->default_branch);

            if (! $hasCommits) {
                if (GitOperations::hasUncommittedChanges($sandbox, $containerName, $workspacePath)) {
                    throw new \RuntimeException(
                        'Agent finished retry with uncommitted changes — run `git add -A && git commit` before returning.'
                    );
                }

                ArtifactPersister::persist($this->task);

                $this->task->update([
                    'status' => TaskStatus::Success,
                    'completed_at' => now(),
                    'result_summary' => $result->resultSummary,
                    'model_used' => config('yak.default_model'),
                ]);

                TaskLogger::info($this->task, 'Answered without code changes (retry)');

                $message = YakPersonality::generate(NotificationType::Result, $result->resultSummary);
                SendNotificationJob::dispatch($this->task, NotificationType::Result, $message);

                return;
            }

            $this->task->update([
                'status' => TaskStatus::AwaitingCi,
                'result_summary' => $result->resultSummary,
                'model_used' => config('yak.default_model'),
            ]);

            // Force push from sandbox (retry overwrites the previous attempt)
            $pushResult = $sandbox->run($containerName, "cd {$workspacePath} && git push --force-with-lease origin {$this->task->branch_name}", timeout: 60);

            if ($pushResult->exitCode() !== 0) {
                throw new \RuntimeException("Git push failed in sandbox: {$pushResult->errorOutput()}");
            }

            TaskLogger::info($this->task, 'Fix pushed — retry', ['branch' => $this->task->branch_name]);
        } else {
            $this->task->update([
                'status' => TaskStatus::AwaitingCi,
                'result_summary' => $result->resultSummary,
                'model_used' => config('yak.default_model'),
            ]);
        }

        if ($repository->ci_system === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed retry on branch {$this->task->branch_name} — waiting for CI to finish before opening a PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    private function handleClarification(AgentRunResult $result): void
    {
        TaskMetricsAccumulator::applyAccumulated($this->task, $result);

        $this->task->update([
            'status' => TaskStatus::AwaitingClarification,
            'clarification_options' => $result->clarificationOptions,
            'clarification_expires_at' => now()->addDays((int) config('yak.clarification_ttl_days')),
        ]);

        DailyCost::accumulate($result->costUsd);

        $numberedOptions = collect($result->clarificationOptions)
            ->map(fn (string $option, int $i) => ($i + 1) . '. ' . $option)
            ->implode("\n");

        SendNotificationJob::dispatch(
            $this->task,
            NotificationType::Clarification,
            "I need some direction before I can continue. Reply with your choice:\n{$numberedOptions}",
        );

        TaskLogger::info($this->task, 'Clarification posted (retry)');
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
