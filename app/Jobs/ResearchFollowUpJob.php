<?php

namespace App\Jobs;

use App\Agents\ClaudeCodeOutputParser;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\ClaimsTask;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ReportsResearchToSource;
use App\Jobs\Concerns\RetriesWithoutStaleSession;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\HoldsForClaudeAuth;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\Telemetry\RunRecorder;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Answers a follow-up question on a finished research task. Read-only like
 * ResearchYakJob: it resumes the research session, replies in the
 * conversation, and attaches a revised report only when the agent writes one.
 */
class ResearchFollowUpJob implements ShouldBeUnique, ShouldQueue
{
    use AsksClarifyingQuestions;
    use ClaimsTask;
    use HandlesAgentJobFailure;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ReportsResearchToSource;
    use RetriesWithoutStaleSession;

    /** Where the previous report sits in the sandbox for the agent to read. */
    private const PREVIOUS_REPORT_PATH = '.yak-artifacts/previous-research.html';

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
            $this->runFollowUp($agent);
        } finally {
            $this->finishParkingForQuestions();
            TaskContext::clear();
        }
    }

    private function runFollowUp(AgentRunner $agent): void
    {
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($repository === null) {
            $this->handleError("Repository '{$this->task->repo}' not found or not configured in Yak.");

            return;
        }

        // Normally already claimed by the ClaimsTaskAtomically middleware;
        // claimTask() is idempotent per instance, so this is the real claim
        // only when handle() is called directly.
        if (! $this->claimTask()) {
            return;
        }

        $sandbox = app(IncusSandboxManager::class);
        $containerName = null;
        $recorder = RunRecorder::start($this->task, TaskRunKind::FollowUp, self::class, $this->task->dispatched_at ?? $this->queuedAt);

        TaskLogger::info($this->task, 'Picked up by worker — research follow-up');

        try {
            $containerName = $sandbox->create($this->task, $repository);
            $recorder->mark('sandbox_create');
            TaskLogger::info($this->task, 'Sandbox created for research follow-up', ['container' => $containerName]);

            $workspacePath = IncusSandboxManager::workspacePath();
            $sandbox->injectGitCredentials($containerName);
            $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$repository->default_branch}", timeout: 60);
            $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$repository->default_branch}", timeout: 30);
            $sandbox->run($containerName, "cd {$workspacePath} && git reset --hard origin/{$repository->default_branch}", timeout: 30);

            $hasPreviousReport = $this->stagePreviousReport($sandbox, $containerName);
            $sandbox->pushSessionTranscript($containerName, $this->task->session_id);
            $recorder->mark('git_prepare');

            $request = new AgentRunRequest(
                prompt: $this->promptFor(fn (): string => YakPromptBuilder::researchFollowUpPrompt(
                    (string) $this->task->description,
                    $this->previousSummary(),
                    $hasPreviousReport,
                )),
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

            if ($result->isError) {
                TaskMetricsAccumulator::record($this->task, $result);
                $this->handleError($result->failureMessage());

                return;
            }

            if ($this->askIfNeeded($result)) {
                return;
            }

            // Questions ignored at the round limit must not reach the output.
            $result = $result->withResultSummary(ClaudeCodeOutputParser::stripClarificationBlock($result->resultSummary));

            $this->handleSuccess($result, $sandbox, $containerName);
            $recorder->mark('post_agent');
        } catch (ClaudeAuthException $e) {
            Log::error('ResearchFollowUpJob auth failure', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
            $recorder->failed($e, 'claude_auth');
            $this->handleError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('ResearchFollowUpJob failed', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);
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

    /**
     * The previous turn's answer, carried into the prompt so the agent has
     * context even when the session transcript cannot be restored.
     */
    private function previousSummary(): ?string
    {
        $parent = $this->task->parent;

        return $parent !== null ? $parent->result_summary : null;
    }

    /**
     * Copies the newest research report of the conversation into the sandbox
     * next to where a revised report would be written. Returns whether a
     * report was staged.
     */
    private function stagePreviousReport(IncusSandboxManager $sandbox, string $containerName): bool
    {
        $artifact = $this->task->latestResearchArtifact();

        if ($artifact === null || ! Storage::disk('artifacts')->exists($artifact->disk_path)) {
            return false;
        }

        $workspacePath = IncusSandboxManager::workspacePath();
        $remotePath = "{$workspacePath}/" . self::PREVIOUS_REPORT_PATH;

        $sandbox->run($containerName, 'mkdir -p ' . escapeshellarg(dirname($remotePath)), timeout: 10, asRoot: true);
        $sandbox->pushFile($containerName, Storage::disk('artifacts')->path($artifact->disk_path), $remotePath);
        $sandbox->run($containerName, 'chown -R yak:yak ' . escapeshellarg(dirname($remotePath)), timeout: 10, asRoot: true);

        return true;
    }

    private function handleSuccess(AgentRunResult $result, IncusSandboxManager $sandbox, string $containerName): void
    {
        $summary = $result->resultSummary;

        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        $artifact = $this->collectHtmlArtifact($sandbox, $containerName);
        $artifactUrl = $artifact !== null ? $this->viewerUrl($artifact) : null;

        $this->task->update([
            'status' => TaskStatus::Success,
            'result_summary' => $summary,
            'model_used' => config('yak.default_model'),
            'completed_at' => now(),
        ]);

        TaskLogger::info($this->task, 'Task completed');

        // The link is appended after the personality rewrite so the agent
        // cannot paraphrase it away.
        $notificationMessage = YakPersonality::generate(NotificationType::Result, $summary);

        if ($artifactUrl !== null) {
            $notificationMessage .= "\n\n📑 **[View revised research report]({$artifactUrl})**";
        }

        $this->reportResult($notificationMessage);

        if ($this->task->source === 'linear') {
            if ($artifactUrl !== null) {
                app(LinearNotificationDriver::class)->createIssueAttachment(
                    $this->task,
                    title: 'Revised research report',
                    url: $artifactUrl,
                    subtitle: 'Detailed findings from Yak · HTML',
                );
            }
        }
    }

    private function handleError(string $errorMessage): void
    {
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        TaskLogger::error($this->task, 'Research follow-up failed', ['error' => $errorMessage]);

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        $this->notifySourceOfFailure($errorMessage);
    }
}
