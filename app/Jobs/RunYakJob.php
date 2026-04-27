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

class RunYakJob implements ShouldQueue
{
    use HandlesAgentJobFailure;
    use Queueable;

    // Agent sessions involving browser capture, docker-compose warmup,
    // or long tool calls regularly exceed 10 minutes. Laravel enforces
    // this via pcntl_alarm → posix_kill(getmypid(), SIGKILL), so setting
    // it too low silently murders the worker mid-stream and leaves the
    // sandbox + reserved job orphaned. 3600s matches SetupYakJob's budget.
    public int $timeout = 3600;

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
            new PausesDuringDrain,
            new EnsureRepoReady,
            new EnsureDailyBudget,
        ];
    }

    public function handle(AgentRunner $agent): void
    {
        TaskContext::set($this->task);

        try {
            $this->runTask($agent);
        } finally {
            TaskContext::clear();
        }
    }

    private function runTask(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($repository === null) {
            $activeSlugs = Repository::where('is_active', true)->pluck('slug')->implode(', ');
            $message = $this->task->repo === 'unknown'
                ? "Could not determine which repo to use. Add `repo: <slug>` to the task description, or mark a repo as default. Active repos: {$activeSlugs}."
                : "Repository '{$this->task->repo}' not found or not configured in Yak. Active repos: {$activeSlugs}.";

            $this->task->update([
                'status' => TaskStatus::Failed,
                'error_log' => $message,
                'completed_at' => now(),
            ]);

            TaskLogger::error($this->task, 'Task failed — repo not resolved', ['repo' => $this->task->repo]);

            return;
        }

        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;

        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'attempts' => $this->task->attempts + 1,
        ]);

        TaskLogger::info($this->task, 'Picked up by worker', ['attempt' => $this->task->attempts + 1]);

        // One-shot "starting work" progress message on the first
        // attempt, gated by yak.emit_start_progress. Closes the silent
        // gap between ack and first push (can be several minutes for
        // research tasks). Skipped on retries to avoid re-notifying.
        if ((int) $this->task->attempts === 1 && (bool) config('yak.emit_start_progress', true)) {
            SendNotificationJob::dispatch(
                $this->task,
                NotificationType::Progress,
                "Starting on `{$this->task->repo}` — exploring the codebase now.",
            );
        }

        try {
            // Create isolated sandbox container (instant CoW clone from snapshot)
            $containerName = $sandbox->create($this->task, $repository);
            TaskLogger::info($this->task, 'Sandbox created', ['container' => $containerName]);

            $this->prepareBranch($sandbox, $containerName, $repository);

            $prompt = $this->assemblePrompt();

            $result = $agent->run(new AgentRunRequest(
                prompt: $prompt,
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
                TaskMetricsAccumulator::applyFresh($this->task, $result);

                $errorMessage = $result->failureMessage();

                Log::channel('yak')->error('Agent error details', [
                    'task_id' => $this->task->id,
                    'error' => $errorMessage,
                    'num_turns' => $result->numTurns,
                    'cost_usd' => $result->costUsd,
                    'duration_ms' => $result->durationMs,
                ]);

                $this->handleError($errorMessage);

                return;
            }

            TaskLogger::info($this->task, 'Assessment complete');

            if ($result->clarificationNeeded) {
                $this->handleClarification($result);

                return;
            }

            // Pull .yak-artifacts/ out of the sandbox…
            SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);

            // …then immediately turn the files into Artifact DB rows and
            // dispatch RenderVideoJob. This used to happen only after CI
            // went green (inside ProcessCIResultJob), which meant the
            // walkthrough video + screenshots weren't visible on the task
            // page until the full Drone build finished, and Remotion
            // rendering sat idle instead of running in parallel with CI.
            ArtifactPersister::persist($this->task);

            $this->handleSuccess($repository, $result, $sandbox, $containerName);
        } catch (ClaudeAuthException $e) {
            Log::error('RunYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunYakJob failed', [
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
        $baseBranchName = GitOperations::branchName($this->task->external_id);
        $defaultBranch = $repository->default_branch;

        // Configure git identity + credentials in the sandbox
        $sandbox->configureGitIdentity($containerName);
        $sandbox->injectGitCredentials($containerName);

        // Fetch latest default branch
        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$defaultBranch}", timeout: 60);

        // Avoid colliding with branches from previous attempts that may
        // already be pushed to the remote.
        $branchName = GitOperations::resolveAvailableBranchName(
            $baseBranchName,
            function (string $candidate) use ($sandbox, $containerName, $workspacePath): bool {
                $result = $sandbox->run(
                    $containerName,
                    "cd {$workspacePath} && git ls-remote --heads origin " . escapeshellarg($candidate),
                    timeout: 30,
                );

                return trim($result->output()) !== '';
            },
        );

        // Create the branch inside the sandbox
        $sandbox->run($containerName, "cd {$workspacePath} && git checkout -b {$branchName} origin/{$defaultBranch}", timeout: 30);

        $this->task->update(['branch_name' => $branchName]);
    }

    private function assemblePrompt(): string
    {
        $metadata = self::parseMetadata($this->task->context);

        return YakPromptBuilder::taskPrompt($this->task, $metadata);
    }

    /**
     * Parse task context as JSON metadata, returning empty array for plain text or null.
     *
     * @return array<string, mixed>
     */
    private static function parseMetadata(?string $context): array
    {
        if ($context === null || $context === '') {
            return [];
        }

        /** @var array<string, mixed>|null */
        $decoded = json_decode($context, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        TaskMetricsAccumulator::applyFresh($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');

        if ($this->task->branch_name !== null) {
            // Safety check: verify not on default branch
            $branchResult = $sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10);
            $currentBranch = trim($branchResult->output());

            if ($currentBranch === $repository->default_branch) {
                throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
            }

            $hasCommits = GitOperations::hasNewCommits($sandbox, $containerName, $workspacePath, $repository->default_branch);

            if (! $hasCommits) {
                // Dirty tree + no commits means the agent edited files but
                // forgot to `git add && git commit` before returning. Treat
                // as a real failure so the retry path (RetryYakJob) picks it
                // up with a clearer hint — otherwise the work silently
                // disappears and the task claims success.
                if (GitOperations::hasUncommittedChanges($sandbox, $containerName, $workspacePath)) {
                    throw new \RuntimeException(
                        'Agent finished with uncommitted changes — run `git add -A && git commit` before returning.'
                    );
                }

                // Clean tree + no commits: the agent legitimately answered
                // without writing code. Artifacts were already persisted
                // in the main handle() flow above. Skip push + CI.
                $this->task->update([
                    'status' => TaskStatus::Success,
                    'completed_at' => now(),
                    'result_summary' => $result->resultSummary,
                    'model_used' => config('yak.default_model'),
                ]);

                TaskLogger::info($this->task, 'Answered without code changes');

                $message = YakPersonality::generate(NotificationType::Result, $result->resultSummary);
                SendNotificationJob::dispatch($this->task, NotificationType::Result, $message);

                return;
            }

            $this->task->update($this->postAgentUpdate($repository, $result));

            // Refresh the baked-in credential helper — the agent may have run
            // long enough for the token fetched during prepareBranch() to
            // expire, which would surface as a 401 on push.
            $sandbox->injectGitCredentials($containerName);

            $pushResult = $sandbox->run($containerName, "cd {$workspacePath} && git push origin {$this->task->branch_name}", timeout: 60);

            if ($pushResult->exitCode() !== 0) {
                throw new \RuntimeException("Git push failed in sandbox: {$pushResult->errorOutput()}");
            }

            TaskLogger::info($this->task, 'Fix pushed', ['branch' => $this->task->branch_name]);
        } else {
            $this->task->update($this->postAgentUpdate($repository, $result));
        }

        if ($repository->ci_system === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed fix on branch {$this->task->branch_name} — waiting for CI to finish before opening a PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function postAgentUpdate(Repository $repository, AgentRunResult $result): array
    {
        $update = [
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ];

        // For repos with real CI, park the task in AwaitingCi until the
        // webhook/poll flips it. For ci_system=none there's nothing to wait
        // for; leave the status as Running so ProcessCIResultJob can move
        // straight to Success — and, if PR creation blows up, the failed()
        // recovery path moves it to Failed instead of stranding it in a
        // misleading "awaiting_ci" state on a repo with no CI.
        if ($repository->ci_system !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
        }

        return $update;
    }

    private function handleClarification(AgentRunResult $result): void
    {
        TaskMetricsAccumulator::applyFresh($this->task, $result);

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

        TaskLogger::info($this->task, 'Clarification posted');
    }

    private function handleError(string $errorMessage): void
    {
        // Don't downgrade a user-cancelled task back to Failed — the
        // cancel action already set a terminal status and destroyed
        // the sandbox; this error is the expected aftermath.
        if ($this->task->fresh()?->status === TaskStatus::Cancelled) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);
    }
}
