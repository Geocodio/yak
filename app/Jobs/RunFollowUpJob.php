<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Exceptions\ExternalBranchPushException;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesFollowUpRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\RetriesWithoutStaleSession;
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

class RunFollowUpJob implements ShouldQueue
{
    use AsksClarifyingQuestions;
    use FinishesFollowUpRuns;
    use HandlesAgentJobFailure;
    use InteractsWithQueue;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ResumesAgentOnExistingBranch;
    use RetriesWithoutStaleSession;
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
            $this->finishParkingForQuestions();
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

        if (config('yak.threads.enabled')) {
            $branchName = $this->task->branch_name;

            $started = $this->startThreadTurn(
                $repository,
                TaskRunKind::FollowUp,
                fn (): string => $this->promptFor(fn (): string => YakPromptBuilder::followUpPrompt((string) $this->task->description)),
                fn (IncusSandboxManager $sandbox, string $container) => $this->prepareThreadBranch($sandbox, $container, $repository, $branchName),
                $this->task->attachments->all(),
                statusWhileWaiting: TaskStatus::Pending,
            );

            if ($started) {
                $this->consumeAnswers();
            }

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
                prompt: $this->promptFor(fn (): string => YakPromptBuilder::followUpPrompt((string) $this->task->description)),
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: $this->task->session_id,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
                attachments: $this->task->attachments->all(),
            );

            $recorder->agentStarted($request);
            $result = $this->runAgentWithStaleSessionFallback($agent, $request);
            $recorder->agentFinished($result);
            $this->consumeAnswers();

            $this->finishFollowUpResult($repository, $result, $sandbox, $containerName, $recorder);
        } catch (ClaudeAuthException $e) {
            Log::error('RunFollowUpJob auth failure', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $recorder->failed($e, 'claude_auth');
            $this->handleError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunFollowUpJob failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $recorder->failed($e);
            $this->handleError($e->getMessage(), isUserFacing: $e instanceof ExternalBranchPushException);
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
}
