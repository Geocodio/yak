<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\GitOperations;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\RetriesWithoutStaleSession;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\Middleware\PreventBranchOverlap;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\FollowUpSummaryParser;
use App\Services\IncusSandboxManager;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\Telemetry\RunRecorder;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
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

        // started_at is what makes the run visible in the conversation
        // thread (see ThreadBuilder) — without it a follow-up leaves no
        // trace, whether it succeeds or fails.
        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            // Recorded so a drain interruption can tell this apart from a
            // fresh RunYakJob run — yak:resume-interrupted-tasks must never
            // resume a follow-up as a plain RunYakJob, which would get a
            // fresh branch and lose its follow-up context.
            'claimed_job_class' => self::class,
        ]);
        TaskLogger::info($this->task, 'Picked up by worker — follow-up');

        if ($this->task->branch_name === null) {
            $this->handleError('Follow-up task has no branch to push to.');

            return;
        }

        $recorder = RunRecorder::start($this->task, TaskRunKind::FollowUp, self::class, $this->queuedAt);

        try {
            $containerName = $sandbox->create($this->task, $repository);
            $recorder->mark('sandbox_create');
            TaskLogger::info($this->task, 'Sandbox created for follow-up', ['container' => $containerName]);

            $branchName = $this->task->branch_name;
            $this->prepareExistingBranch($sandbox, $containerName, $repository, $branchName);

            $sandbox->pushSessionTranscript($containerName, $this->task->session_id);
            $recorder->mark('git_prepare');

            $request = new AgentRunRequest(
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
            );

            $recorder->agentStarted($request);
            $result = $this->runAgentWithStaleSessionFallback($agent, $request);
            $recorder->agentFinished($result);

            if ($result->isError) {
                TaskMetricsAccumulator::record($this->task, $result);
                $this->handleError($result->failureMessage());

                return;
            }

            SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);
            ArtifactPersister::persist($this->task);

            $this->handleSuccess($repository, $result, $sandbox, $containerName, $recorder);
        } catch (ClaudeAuthException $e) {
            Log::error('RunFollowUpJob auth failure', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $recorder->failed($e, 'claude_auth');
            $this->handleError($e->getMessage());
            SendNotificationJob::dispatch($this->task, NotificationType::Error, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunFollowUpJob failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
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

    private function handleSuccess(Repository $repository, AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName, RunRecorder $recorder): void
    {
        TaskMetricsAccumulator::record($this->task, $result);

        $parsed = app(FollowUpSummaryParser::class)->parse($result->resultSummary);

        DailyCost::accumulate($result->costUsd);

        $branchName = $this->task->branch_name;

        if ($branchName === null) {
            throw new \RuntimeException('Follow-up reached the push step with no branch name.');
        }

        $recorder->gitStats(GitOperations::changeStats($sandbox, $containerName, IncusSandboxManager::workspacePath(), "origin/{$branchName}"));

        if (! $this->hasNewCommits($sandbox, $containerName, $branchName)) {
            // The follow-up prompt allows answering a question without
            // changing code. No commits means there's nothing to push or
            // wait on CI for -- resolve the task right away instead of
            // parking it in AwaitingCi for a check_suite that never comes.
            $this->task->update([
                'result_summary' => $parsed->changes !== '' ? $parsed->changes : null,
                'pr_body_update' => $parsed->description,
                'review_replies' => $parsed->replies !== [] ? $parsed->replies : null,
                'model_used' => config('yak.default_model'),
            ]);

            TaskLogger::info($this->task, 'Follow-up produced no commits; skipping push');

            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();

            return;
        }

        $update = [
            'result_summary' => $parsed->changes !== '' ? $parsed->changes : null,
            'pr_body_update' => $parsed->description,
            'review_replies' => $parsed->replies !== [] ? $parsed->replies : null,
            'model_used' => config('yak.default_model'),
        ];

        if ($repository->ci_system !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
        }

        $this->task->update($update);

        $this->pushExistingBranch($sandbox, $containerName, $repository, $branchName);
        $recorder->mark('post_agent');
        TaskLogger::info($this->task, 'Follow-up pushed', ['branch' => $branchName]);

        if ($repository->ci_system === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed your changes on branch {$branchName} — waiting for CI before updating the PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    /**
     * Whether the sandbox's HEAD has commits the follow-up branch's remote
     * doesn't have yet. A failed command or a non-numeric result (an
     * unexpected command output) is treated as unknown and defaults to
     * true, so the safer, existing push-and-wait path runs rather than
     * silently dropping work.
     */
    private function hasNewCommits(IncusSandboxManager $sandbox, string $containerName, string $branchName): bool
    {
        $workspacePath = IncusSandboxManager::workspacePath();

        $result = $sandbox->run(
            $containerName,
            "cd {$workspacePath} && git rev-list --count origin/{$branchName}..HEAD",
            timeout: 15,
        );

        if ($result->exitCode() !== 0) {
            return true;
        }

        $output = trim($result->output());

        // An empty result and a clean integer both parse safely with
        // (int) casting; anything else (a git error, unexpected text) is
        // treated as unknown and defaults to the existing push-and-wait
        // path rather than silently dropping work.
        if ($output !== '' && ! ctype_digit($output)) {
            return true;
        }

        return (int) $output > 0;
    }

    private function handleError(string $errorMessage): void
    {
        // Don't overwrite a task that's already terminal — see
        // RunYakJob::handleError() for the full reasoning.
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Follow-up failed', ['error' => $errorMessage]);
    }
}
