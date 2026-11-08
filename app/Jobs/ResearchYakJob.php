<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\ClaimsTask;
use App\Jobs\Concerns\FinishesResearchRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ReportsResearchToSource;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\PromptResolver;
use App\Services\TaskLogger;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class ResearchYakJob implements ShouldBeUnique, ShouldQueue
{
    use AsksClarifyingQuestions;
    use ClaimsTask;
    use FinishesResearchRuns;
    use HandlesAgentJobFailure;
    use InteractsWithQueue;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ReportsResearchToSource;
    use StartsThreadTurns;

    public int $timeout = 3600;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    /**
     * Releases from PausesDuringDrain
     * increment the attempt counter, so the worker's --tries=3 would fail a
     * held job after three minutes. This method takes precedence over tries
     * and lets a job wait out a drain.
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
     * ClaimsTaskAtomically. See RunYakJob::uniqueFor() for the reasoning
     * on the 900s window.
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
            new ClaimsTaskAtomically,
            new EnsureRepoReady,
        ];
    }

    public function handle(): void
    {
        TaskContext::set($this->task);

        try {
            $this->runResearch();
        } finally {
            $this->finishParkingForQuestions();
            TaskContext::clear();
        }
    }

    /**
     * The task's repo is `unknown` or names a repository that no longer
     * exists, so there is nothing to explore. Fails the task and tells the
     * requester instead of throwing out of the queue.
     */
    private function failForUnresolvedRepository(): void
    {
        $activeSlugs = Repository::where('is_active', true)->pluck('slug')->implode(', ');
        $message = $this->task->repo === 'unknown'
            ? "Could not determine which repo to use. Add `repo: <slug>` to the task description, or mark a repo as default. Active repos: {$activeSlugs}."
            : "Repository '{$this->task->repo}' not found or not configured in Yak. Active repos: {$activeSlugs}.";

        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $message,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed — repo not resolved', ['repo' => $this->task->repo]);

        $this->notifySourceOfFailure($message);
    }

    /**
     * Puts the working tree on the latest default branch and returns whether
     * the task drafts a risk profile, which records the revision it read.
     */
    private function syncDefaultBranch(IncusSandboxManager $sandbox, string $containerName, Repository $repository): bool
    {
        // Ensure we're on the default branch with latest code. The
        // sandbox snapshot was taken whenever Setup last ran, so we
        // must fetch + reset to origin to pick up commits merged
        // since then — otherwise the agent reads stale source.
        $workspacePath = IncusSandboxManager::workspacePath();
        $sandbox->injectGitCredentials($containerName);
        $fetch = $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$repository->default_branch}", timeout: 60);
        $checkout = $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$repository->default_branch}", timeout: 30);
        $reset = $sandbox->run($containerName, "cd {$workspacePath} && git reset --hard origin/{$repository->default_branch}", timeout: 30);
        $context = json_decode((string) $this->task->context, true) ?: [];
        $riskProfile = ($context['risk_profile_draft'] ?? false) === true;
        if ($riskProfile) {
            $sha = $sandbox->run($containerName, "cd {$workspacePath} && git rev-parse HEAD", timeout: 30);
            if ($fetch->exitCode() !== 0 || $checkout->exitCode() !== 0 || $reset->exitCode() !== 0 || $sha->exitCode() !== 0) {
                throw new \RuntimeException('Could not establish the risk profile source revision.');
            }
            $context['risk_profile_source_sha'] = trim($sha->output());
            if (preg_match('/^[a-f0-9]{40,64}$/', $context['risk_profile_source_sha']) !== 1) {
                throw new \RuntimeException('Risk profile source revision is not a commit SHA.');
            }
            $this->task->update(['context' => json_encode($context)]);
        }

        return $riskProfile;
    }

    /**
     * Research is read-only, so a turn on a thread that has already worked
     * leaves the tree as it is. A thread with no turns yet has nothing to
     * disturb and starts from the latest default branch; so does a risk
     * profile draft, which records the revision it read.
     */
    private function startResearchTurn(Repository $repository): void
    {
        $context = json_decode((string) $this->task->context, true) ?: [];
        $riskProfile = ($context['risk_profile_draft'] ?? false) === true;

        $started = $this->startThreadTurn(
            $repository,
            TaskRunKind::Research,
            fn (): string => $this->promptFor(fn (): string => $riskProfile ? app(PromptResolver::class)->render('tasks-risk-profile') : YakPromptBuilder::taskPrompt($this->task)),
            function (IncusSandboxManager $sandbox, string $container, Thread $thread) use ($repository, $riskProfile): void {
                if ($riskProfile || $thread->turns()->doesntExist()) {
                    $this->syncDefaultBranch($sandbox, $container, $repository);
                }
            },
            $this->task->attachments->all(),
        );

        if ($started) {
            $this->consumeAnswers();
        }
    }

    private function runResearch(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($repository === null) {
            $this->failForUnresolvedRepository();

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
        TaskLogger::info($this->task, 'Picked up by worker — research');

        // One-shot "starting research" progress on first attempt,
        // matching the RunYakJob cadence. Research tasks can take
        // minutes; this keeps the channel alive while we explore.
        if (! $resuming && (int) $this->task->attempts === 1 && (bool) config('yak.emit_start_progress', true)) {
            SendNotificationJob::dispatch(
                $this->task,
                NotificationType::Progress,
                "Diving into `{$this->task->repo}` — researching now, no code changes.",
            );
        }

        $this->startResearchTurn($repository);
    }
}
