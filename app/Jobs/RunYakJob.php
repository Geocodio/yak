<?php

namespace App\Jobs;

use App\Contracts\SandboxShell;
use App\Enums\NotificationType;
use App\Enums\RepositoryRole;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\ClaimsTask;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunYakJob implements ShouldBeUnique, ShouldQueue
{
    use AsksClarifyingQuestions;
    use ClaimsTask;
    use HandlesAgentJobFailure;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ResumesAgentOnExistingBranch;
    use StartsThreadTurns;

    // The job only prepares the working tree and writes the turn; the agent
    // runs on the thread's runner and FinishRunYakTurnJob does the rest.
    public int $timeout = 900;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    /**
     * Releases from PausesDuringDrain and from a turn that waits for a slot or
     * an account increment the attempt counter, so the worker's --tries=3
     * would fail a waiting job after a few minutes. This method takes
     * precedence over tries and lets a job wait.
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
            new ClaimsTaskAtomically,
            new EnsureRepoReady,
            new EnsureDailyBudget,
        ];
    }

    public function handle(): void
    {
        TaskContext::set($this->task);

        try {
            $this->runTask();
        } finally {
            TaskContext::clear();
        }
    }

    private function runTask(): void
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

        // The job ends once the turn is written. The thread driver reports the
        // turn's end and DispatchAfterTurnWork queues FinishRunYakTurnJob.
        $this->startThreadTurn(
            $repository,
            $resuming ? TaskRunKind::Clarification : TaskRunKind::Initial,
            function (Thread $thread) use ($resuming): string {
                if (! $resuming) {
                    return $this->assemblePrompt();
                }

                $answersPrompt = YakPromptBuilder::clarificationAnswersPrompt($this->task);

                // The thread's session already holds the task; a new thread needs the task itself, then the answers.
                return $thread->runs()->exists() ? $answersPrompt : $this->assemblePrompt() . "\n\n" . $answersPrompt;
            },
            function (SandboxShell $shell, Thread $thread) use ($repository, $resuming): void {
                // A continuing thread's working tree is as its last turn left it.
                if ($thread->runs()->exists()) {
                    return;
                }

                if ($resuming && $this->task->branch_name !== null) {
                    $this->prepareExistingBranch($shell, '', $repository, $this->task->branch_name);
                } else {
                    $this->prepareBranch($shell, $thread, $repository);
                }
            },
            $this->task->attachments->all(),
        );
    }

    private function prepareBranch(SandboxShell $shell, Thread $thread, Repository $repository): void
    {
        $workspacePath = IncusSandboxManager::workspacePath();
        $baseBranchName = GitOperations::branchName($this->task->external_id);
        $defaultBranch = $repository->default_branch;

        $shell->run('', "cd {$workspacePath} && git fetch origin {$defaultBranch}", timeout: 60);

        // Avoid colliding with branches from previous attempts that may
        // already be pushed to the remote.
        $branchName = GitOperations::resolveAvailableBranchName(
            $baseBranchName,
            function (string $candidate) use ($shell, $workspacePath): bool {
                $result = $shell->run('', "cd {$workspacePath} && git ls-remote --heads origin " . escapeshellarg($candidate), timeout: 30);

                return trim($result->output()) !== '';
            },
        );

        // -B so a retry before the first turn ran can take the branch over again.
        $shell->run('', "cd {$workspacePath} && git checkout -B {$branchName} origin/{$defaultBranch}", timeout: 30);

        $this->task->update(['branch_name' => $branchName]);
        $thread->forceFill(['branch' => $branchName])->save();
        $thread->repositories()->where('role', RepositoryRole::Primary)->update(['branch' => $branchName]);
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

    private function handleError(string $errorMessage): void
    {
        // Don't overwrite a task that's already terminal — cancelled
        // mid-run, force-failed by a deploy drain with an accurate
        // reason, or finalised by a parallel path. Overwriting it here
        // would replace that reason with whatever this run's own error
        // happened to be (often just the abnormal-termination artifact of
        // whatever interrupted it).
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);

        $this->notifySourceOfFailure($errorMessage);
    }
}
