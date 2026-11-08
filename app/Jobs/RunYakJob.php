<?php

namespace App\Jobs;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Exceptions\SandboxCapacityReached;
use App\GitOperations;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\ClaimsTask;
use App\Jobs\Concerns\FinishesAgentRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\RetriesWithoutStaleSession;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskAttachmentStager;
use App\Services\TaskLogger;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class RunYakJob implements ShouldBeUnique, ShouldQueue
{
    use AsksClarifyingQuestions;
    use ClaimsTask;
    use FinishesAgentRuns;
    use HandlesAgentJobFailure;
    use HandlesWrongRepository;
    use InteractsWithQueue;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ResumesAgentOnExistingBranch;
    use RetriesWithoutStaleSession;

    // Agent sessions involving browser capture, docker-compose warmup,
    // or long tool calls regularly exceed 10 minutes. Laravel enforces
    // this via pcntl_alarm → posix_kill(getmypid(), SIGKILL), so setting
    // it too low silently murders the worker mid-stream and leaves the
    // sandbox + reserved job orphaned. (SetupYakJob gets a higher budget
    // because heavy repos can take over an hour to build from scratch.)
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
        public YakTask $task,
    ) {
        $this->queuedAt = CarbonImmutable::now();
        $this->onQueue('yak-claude');
    }

    /**
     * Dispatch-level dedupe, defence in depth against the atomic claim in
     * ClaimsTaskAtomically. Longer than change 3's 10-minute lost-pending
     * sweep threshold so a lock orphaned by a SIGKILLed worker still
     * clears before the sweep would otherwise re-dispatch into a locked-out
     * task.
     */
    public function uniqueId(): string
    {
        return "task:{$this->task->id}";
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new PausesDuringDrain,
            new HoldsForClaudeAuth,
            new ClaimsTaskAtomically,
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
            $this->finishParkingForQuestions();
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

            if (! $this->taskIsTerminal($this->task->fresh())) {
                $this->task->update([
                    'status' => TaskStatus::Failed,
                    'error_log' => $message,
                    'completed_at' => now(),
                ]);

                TaskLogger::error($this->task, 'Task failed — repo not resolved', ['repo' => $this->task->repo]);

                $this->notifySourceOfFailure($message);
            }

            return;
        }

        // Normally already claimed by the ClaimsTaskAtomically middleware
        // before this method ran; claimTask() is idempotent per instance,
        // so this is the actual claim only when handle() is called
        // directly (as in tests), bypassing the middleware pipeline.
        if (! $this->claimTask()) {
            return;
        }

        $resuming = $this->resumingWithAnswers();

        if (config('yak.threads.enabled')) {
            $this->announceStart($resuming);
            $this->startThreadTurn($repository, $resuming);

            return;
        }

        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;
        $recorder = RunRecorder::start($this->task, TaskRunKind::Initial, self::class, $this->task->dispatched_at ?? $this->queuedAt);

        $this->announceStart($resuming);

        try {
            // Create isolated sandbox container (instant CoW clone from snapshot)
            $containerName = $sandbox->create($this->task, $repository);
            $recorder->mark('sandbox_create');
            TaskLogger::info($this->task, 'Sandbox created', ['container' => $containerName]);

            if ($resuming && $this->task->branch_name !== null) {
                $this->prepareExistingBranch($sandbox, $containerName, $repository, $this->task->branch_name);
                $sandbox->pushSessionTranscript($containerName, $this->task->session_id);
            } else {
                $this->prepareBranch($sandbox, $containerName, $repository);
            }
            $recorder->mark('git_prepare');

            $prompt = $this->promptFor(fn (): string => $this->assemblePrompt());

            $request = new AgentRunRequest(
                prompt: $prompt,
                systemPrompt: YakPromptBuilder::systemPrompt($this->task),
                containerName: $containerName,
                timeoutSeconds: $this->timeout - 30,
                maxBudgetUsd: (float) config('yak.max_budget_per_task'),
                maxTurns: (int) config('yak.max_turns'),
                model: (string) config('yak.default_model'),
                resumeSessionId: $resuming ? $this->task->session_id : null,
                mcpConfigPath: config('yak.mcp_config_path'),
                task: $this->task,
                attachments: $this->task->attachments->all(),
            );

            $recorder->agentStarted($request);
            $result = $this->runAgentWithStaleSessionFallback($agent, $request);
            $recorder->agentFinished($result);
            $this->consumeAnswers();

            $this->finishAgentResult($repository, $result, $sandbox, $containerName, $recorder);
        } catch (ClaudeAuthException $e) {
            Log::error('RunYakJob auth failure', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $recorder->failed($e, 'claude_auth');
            $this->handleError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RunYakJob failed', [
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

    private function announceStart(bool $resuming): void
    {
        TaskLogger::info($this->task, 'Picked up by worker', ['attempt' => $this->task->attempts + 1]);

        // One-shot "starting work" progress message on the first
        // attempt, gated by yak.emit_start_progress. Closes the silent
        // gap between ack and first push (can be several minutes for
        // research tasks). Skipped on retries to avoid re-notifying.
        if (! $resuming && (int) $this->task->attempts === 1 && (bool) config('yak.emit_start_progress', true)) {
            SendNotificationJob::dispatch(
                $this->task,
                NotificationType::Progress,
                "Starting on `{$this->task->repo}` — exploring the codebase now.",
            );
        }
    }

    /**
     * Wakes the thread's sandbox, prepares the branch and writes the turn.
     * The job ends here; the driver reports the turn's end and
     * DispatchAfterTurnWork queues FinishRunYakTurnJob for the rest.
     */
    private function startThreadTurn(Repository $repository, bool $resuming): void
    {
        try {
            $thread = app(ThreadFactory::class)->forTask($this->task);
            $container = app(ThreadSandbox::class)->ensureAwake($thread, $repository);
            $sandbox = app(IncusSandboxManager::class);

            if ($this->task->branch_name === null) {
                $this->prepareBranch($sandbox, $container, $repository);
            } elseif (! $this->isOnBranch($sandbox, $container, $this->task->branch_name)) {
                $this->prepareExistingBranch($sandbox, $container, $repository, $this->task->branch_name);
            }

            $prompt = $this->promptFor(fn (): string => $this->assemblePrompt());
            $attachments = (new TaskAttachmentStager($sandbox))->stage($container, $this->task->attachments->all());

            if ($attachments !== '') {
                $prompt = rtrim($prompt) . "\n\n" . $attachments;
            }

            $run = app(ThreadTurns::class)->start(
                $this->task,
                $resuming ? TaskRunKind::Clarification : TaskRunKind::Initial,
                $prompt,
                YakPromptBuilder::systemPrompt($this->task),
                $this->task->started_by_user_id,
                $container,
            );

            if ($run === null) {
                $this->handleError('The thread is blocked: ' . ($thread->refresh()->blocked_reason->value ?? 'no usable Claude account') . '.');

                return;
            }

            $this->consumeAnswers();
        } catch (SandboxCapacityReached) {
            // Every awake sandbox is busy: wait for a slot instead of failing the task.
            $this->task->update(['status' => TaskStatus::Pending]);
            $this->release(60);
        } catch (\Throwable $e) {
            Log::error('RunYakJob could not start its turn', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $this->handleError($e->getMessage());
        }
    }

    private function isOnBranch(IncusSandboxManager $sandbox, string $container, string $branch): bool
    {
        $head = $sandbox->run($container, 'cd ' . IncusSandboxManager::workspacePath() . ' && git rev-parse --abbrev-ref HEAD', timeout: 10);

        return trim($head->output()) === $branch;
    }

    private function prepareBranch(IncusSandboxManager $sandbox, string $containerName, Repository $repository): void
    {
        $workspacePath = IncusSandboxManager::workspacePath();
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
}
