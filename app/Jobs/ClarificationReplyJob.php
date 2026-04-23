<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
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

            $result = $agent->run(new AgentRunRequest(
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
                $sandbox->pullClaudeCredentials($containerName);
                $sandbox->destroy($containerName);
            }
        }
    }

    private function prepareBranch(IncusSandboxManager $sandbox, string $containerName, Repository $repository): void
    {
        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');

        // Configure git identity
        $gitName = config('yak.git_user_name', 'Yak');
        $gitEmail = config('yak.git_user_email', 'yak@noreply.github.com');
        $sandbox->run($containerName, 'git config --global user.name ' . escapeshellarg($gitName), timeout: 10);
        $sandbox->run($containerName, 'git config --global user.email ' . escapeshellarg($gitEmail), timeout: 10);

        $this->injectGitCredentials($sandbox, $containerName);

        // Fetch and checkout the task branch
        $branchName = $this->task->branch_name ?? 'yak/' . $this->task->external_id;
        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$branchName}", timeout: 60);
        $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$branchName}", timeout: 30);
    }

    private function injectGitCredentials(IncusSandboxManager $sandbox, string $containerName): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            return;
        }

        $token = app(GitHubAppService::class)->getInstallationToken($installationId);

        $sandbox->run(
            $containerName,
            'git config --global credential.https://github.com.helper ' .
            escapeshellarg("!f() { echo \"protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\"; }; f"),
            timeout: 10,
        );
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
            $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');

            // Safety check
            $branchResult = $sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10);
            $currentBranch = trim($branchResult->output());

            if ($currentBranch === $repository->default_branch) {
                throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
            }

            // Refresh the baked-in credential helper — the agent may have run
            // long enough for the token fetched during prepareBranch() to
            // expire, which would surface as a 401 on push.
            $this->injectGitCredentials($sandbox, $containerName);

            // Force push from sandbox
            $pushResult = $sandbox->run($containerName, "cd {$workspacePath} && git push --force-with-lease origin {$this->task->branch_name}", timeout: 60);

            if ($pushResult->exitCode() !== 0) {
                throw new \RuntimeException("Git push failed in sandbox: {$pushResult->errorOutput()}");
            }

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
