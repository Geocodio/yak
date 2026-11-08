<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\FinishesRetryRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\Middleware\PreventBranchOverlap;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\Telemetry\RunRecorder;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RetryYakJob implements ShouldQueue
{
    use FinishesRetryRuns;
    use HandlesAgentJobFailure;
    use HandlesWrongRepository;
    use InteractsWithQueue;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ResumesAgentOnExistingBranch;
    use StartsThreadTurns;

    public int $timeout = 3600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    /**
     * Releases from PausesDuringDrain and the Claude-auth hold middleware
     * increment the attempt counter, so the worker's --tries=3 would fail a
     * held job after three minutes. This method takes precedence over tries
     * and lets a job wait out a drain or a re-authentication.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * When this job object was built, which is when it went on the queue.
     * Serialised with the job so the run record can measure queue wait.
     */
    public readonly CarbonImmutable $queuedAt;

    public function __construct(
        public readonly YakTask $task,
        public readonly ?string $failureOutput = null,
    ) {
        $this->queuedAt = CarbonImmutable::now();
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
            new HoldsForClaudeAuth,
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
        if ($this->task->targets_external_pr) {
            $this->handleError('Retries are not run on pull requests Yak did not open.');

            return;
        }

        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;
        TaskLogger::info($this->task, 'Picked up by worker — retry', ['attempt' => $this->task->attempts]);

        if (config('yak.threads.enabled')) {
            // The turn resumes the thread's session; the tree is reset to the pushed head first.
            $this->startThreadTurn(
                $repository,
                TaskRunKind::Retry,
                fn (): string => YakPromptBuilder::retryPrompt($this->task, $this->failureOutput),
                fn (IncusSandboxManager $sandbox, string $container) => $this->prepareRetryBranch($sandbox, $container, $repository),
                $this->task->allAttachments->all(),
                statusWhileWaiting: TaskStatus::Retrying,
            );

            return;
        }

        $recorder = RunRecorder::start($this->task, TaskRunKind::Retry, self::class, $this->queuedAt);

        try {
            // Create sandbox from repo snapshot (has the setup environment ready)
            $containerName = $sandbox->create($this->task, $repository);
            $recorder->mark('sandbox_create');
            TaskLogger::info($this->task, 'Sandbox created for retry', ['container' => $containerName]);

            // Configure git and checkout the task branch
            $this->prepareRetryBranch($sandbox, $containerName, $repository);
            $recorder->mark('git_prepare');

            $request = new AgentRunRequest(
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
                attachments: $this->task->allAttachments->all(),
            );

            $recorder->agentStarted($request);
            $result = $agent->run($request);
            $recorder->agentFinished($result);

            $this->finishRetryResult($repository, $result, $sandbox, $containerName, $recorder);
        } catch (ClaudeAuthException $e) {
            Log::error('RetryYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $recorder->failed($e, 'claude_auth');
            $this->handleError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RetryYakJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $recorder->failed($e);
            $this->handleError($e->getMessage());
        } finally {
            $recorder->closePostAgent();

            if ($containerName !== null) {
                $sandbox->pullSessionTranscript($containerName, $this->task->session_id);
                $sandbox->destroy($containerName);
                $recorder->mark('teardown');
            }

            $recorder->finish();
        }
    }

    private function prepareRetryBranch(IncusSandboxManager $sandbox, string $containerName, Repository $repository): void
    {
        $workspacePath = IncusSandboxManager::workspacePath();

        $sandbox->configureGitIdentity($containerName);
        $sandbox->injectGitCredentials($containerName);

        // Refresh origin/{default} so any diff or comparison the agent
        // does against master sees commits merged since the snapshot.
        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$repository->default_branch}", timeout: 60);

        // Fetch the task branch and check it out
        $branchName = $this->task->branch_name ?? 'yak/' . $this->task->external_id;

        if (config('yak.threads.enabled')) {
            $this->resetToPushedHead($sandbox, $containerName, $branchName);
        } else {
            $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$branchName}", timeout: 60);
            $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$branchName}", timeout: 30);
        }

        $this->rebaseOntoDefaultBranch($sandbox, $containerName, $workspacePath, $repository->default_branch);
    }

    /**
     * Replay the task branch on top of the current default branch.
     *
     * A retry exists because CI went red, and often enough the cause was on
     * the default branch and has since been fixed there. Starting the retry
     * from stale base commits makes the agent chase a failure that no longer
     * exists, so bring the branch forward first.
     *
     * A conflict is not fatal: the branch is left exactly as it was fetched
     * and the retry continues against the older base, which is still strictly
     * better than aborting a run the user is waiting on. The agent is told
     * about it through the log so a human reading the task can see why the
     * base is behind.
     */
    private function rebaseOntoDefaultBranch(
        IncusSandboxManager $sandbox,
        string $containerName,
        string $workspacePath,
        string $defaultBranch,
    ): void {
        $rebase = $sandbox->run(
            $containerName,
            "cd {$workspacePath} && git rebase origin/{$defaultBranch}",
            timeout: 120,
        );

        if ($rebase->exitCode() === 0) {
            TaskLogger::info($this->task, "Rebased onto origin/{$defaultBranch} before retry");

            return;
        }

        // Leave no half-applied rebase behind for the agent to trip over.
        $sandbox->run($containerName, "cd {$workspacePath} && git rebase --abort", timeout: 60);

        TaskLogger::warning($this->task, "Could not rebase onto origin/{$defaultBranch} — retrying on the original base", [
            'error' => $rebase->errorOutput(),
        ]);
    }
}
