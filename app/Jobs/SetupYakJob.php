<?php

namespace App\Jobs;

use App\Agents\ClaudeCodeOutputParser;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SetupYakJob implements ShouldQueue
{
    use HandlesAgentJobFailure {
        failed as handleAgentJobFailure;
    }
    use Queueable;

    // Setup on heavy repos legitimately exceeds an hour: local docker image
    // builds, composer/npm installs, migrations, and verification. Task 5431
    // (Geocodio/geocodio) finished the setup twice and was hard-killed during
    // final wrap-up at exactly 3600s both times.
    public int $timeout = 7200;

    // Seconds reserved between the agent's own deadline and the worker's
    // pcntl hard kill, so the agent can wind down and report a result
    // instead of being SIGKILLed mid-stream.
    private const AGENT_SHUTDOWN_BUFFER_SECONDS = 180;

    // A failed setup rarely succeeds on retry without manual intervention,
    // and each retry burns another agent budget. Fail fast; users can
    // re-trigger via the "Re-run Setup" button, which dispatches a fresh task.
    public int $tries = 1;

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
            new PausesDuringDrain,
            new EnsureDailyBudget,
        ];
    }

    /**
     * The worker's hard kill (timeout, crash) lands here without ever
     * reaching handleError(), which normally resets setup_status — so the
     * repo would show 'running' forever. Only downgrade when the task
     * actually failed: failed() can also fire after a successful run if a
     * post-success cleanup step blew up, and a promoted template is ready.
     */
    public function failed(?\Throwable $e): void
    {
        $this->handleAgentJobFailure($e);

        if ($this->task->fresh()?->status === TaskStatus::Failed) {
            Repository::where('slug', $this->task->repo)->first()?->update(['setup_status' => 'failed']);
        }
    }

    public function handle(AgentRunner $agent): void
    {
        TaskContext::set($this->task);

        try {
            $this->runSetup($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runSetup(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        TaskLogger::info($this->task, 'Picked up by worker — setup');
        $repository->update(['setup_status' => 'running']);

        try {
            // Always build setup sandboxes from the empty yak-base, not
            // from any existing repo template. Without this, a re-run of
            // Setup clones from the previously-promoted template whose
            // /workspace is already populated, and `git clone` fails with
            // "destination path already exists and is not empty".
            if (! empty($repository->sandbox_snapshot)) {
                $sandbox->invalidateTemplate($repository);
                $repository->refresh();
            }

            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created', ['container' => $containerName]);

            // Clone the repo inside the sandbox
            $this->cloneRepoInSandbox($sandbox, $containerName, $repository);

            // Checkout default branch
            $workspacePath = IncusSandboxManager::workspacePath();
            $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$repository->default_branch}", timeout: 30);

            TaskLogger::info($this->task, 'Starting Claude agent');

            $result = $agent->run(new AgentRunRequest(
                prompt: YakPromptBuilder::setupPrompt($repository->name),
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->agentTimeoutSeconds(),
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
            ));

            if ($result->isError) {
                $this->handleError(
                    $repository,
                    $result->failureMessage(),
                );

                return;
            }

            $this->handleSuccess($repository, $result, $sandbox, $containerName);
        } catch (ClaudeAuthException $e) {
            Log::error('SetupYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($repository, $e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('SetupYakJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($repository, $e->getMessage());
        } finally {
            // Always clean up the sandbox on setup (we snapshot first on success)
            if ($containerName !== null) {
                $sandbox->destroy($containerName);
            }
        }
    }

    /**
     * Agent budget = job timeout minus time already spent (template
     * invalidation, sandbox create, clone) minus a shutdown buffer.
     * A fixed "$timeout - 30" ignored the pre-agent work, so the agent's
     * graceful deadline landed at the same instant as the worker's pcntl
     * hard kill — and the hard kill won, discarding the whole run.
     */
    private function agentTimeoutSeconds(): int
    {
        $startedAt = $this->task->started_at ?? now();
        $elapsed = (int) $startedAt->diffInSeconds(now());

        return max(60, $this->timeout - $elapsed - self::AGENT_SHUTDOWN_BUFFER_SECONDS);
    }

    private function cloneRepoInSandbox(IncusSandboxManager $sandbox, string $containerName, Repository $repository): void
    {
        if (empty($repository->git_url)) {
            throw new \RuntimeException("Repository {$repository->slug} has no git_url configured.");
        }

        $workspacePath = IncusSandboxManager::workspacePath();

        TaskLogger::info($this->task, "Cloning {$repository->git_url} in sandbox");

        // Configure git identity + credentials inside the sandbox.
        $sandbox->configureGitIdentity($containerName);
        $sandbox->injectGitCredentials($containerName);

        $result = $sandbox->run(
            $containerName,
            "git clone {$repository->git_url} {$workspacePath}",
            timeout: 120,
        );

        if ($result->exitCode() !== 0) {
            throw new \RuntimeException("Failed to clone repository in sandbox: {$result->errorOutput()}");
        }
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        TaskMetricsAccumulator::applyFresh($this->task, $result);

        // Capture the preview_manifest if the agent emitted one.
        $manifest = ClaudeCodeOutputParser::extractPreviewManifest($result->resultSummary);
        if ($manifest !== null) {
            $repository->preview_manifest = $manifest;
            $repository->save();
        }

        // Promote the setup container to a repo template with snapshot.
        // Future tasks for this repo will clone from this snapshot (instant CoW).
        // This also bumps current_template_version to 1+ so DeployBranchJob can proceed.
        $snapshotRef = $sandbox->promoteToTemplate($containerName, $repository);

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        DailyCost::accumulate($result->costUsd);

        TaskLogger::info($this->task, 'Task completed');
        $repository->update([
            'setup_status' => 'ready',
            'sandbox_snapshot' => $snapshotRef,
        ]);
    }

    private function handleError(Repository $repository, string $errorMessage): void
    {
        // Don't downgrade a user-cancelled task back to Failed.
        if ($this->task->fresh()?->status === TaskStatus::Cancelled) {
            $repository->update(['setup_status' => 'failed']);

            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);
        $repository->update(['setup_status' => 'failed']);
    }
}
