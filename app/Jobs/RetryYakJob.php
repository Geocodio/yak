<?php

namespace App\Jobs;

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\Concerns\FinishesRetryRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\Middleware\PreventBranchOverlap;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

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
            new EnsureRepoReady,
        ];
    }

    public function handle(): void
    {
        TaskContext::set($this->task);

        try {
            $this->runRetry();
        } finally {
            TaskContext::clear();
        }
    }

    private function runRetry(): void
    {
        if ($this->task->targets_external_pr) {
            $this->handleError('Retries are not run on pull requests Yak did not open.');

            return;
        }

        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();
        TaskLogger::info($this->task, 'Picked up by worker — retry', ['attempt' => $this->task->attempts]);

        // The turn resumes the thread's session; the tree is reset to the pushed head first.
        $this->startThreadTurn(
            $repository,
            TaskRunKind::Retry,
            fn (): string => YakPromptBuilder::retryPrompt($this->task, $this->failureOutput),
            fn (IncusSandboxManager $sandbox, string $container) => $this->prepareRetryBranch($sandbox, $container, $repository),
            $this->task->allAttachments->all(),
            statusWhileWaiting: TaskStatus::Retrying,
        );
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

        $this->resetToPushedHead($sandbox, $containerName, $branchName);

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
