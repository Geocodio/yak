<?php

namespace App\Jobs;

use App\Contracts\SandboxShell;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\Concerns\FinishesRetryRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\HandlesWrongRepository;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\EnsureDailyBudget;
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
            new EnsureDailyBudget,
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
        TaskLogger::info($this->task, 'Picked up by worker -- retry', ['attempt' => $this->task->attempts]);

        // The turn resumes the thread's active session; the tree is reset to the pushed head first.
        $this->startThreadTurn(
            $repository,
            TaskRunKind::Retry,
            fn (): string => YakPromptBuilder::retryPrompt($this->task, $this->failureOutput),
            fn (SandboxShell $shell) => $this->prepareRetryTree($shell),
            $this->task->allAttachments->all(),
            statusWhileWaiting: TaskStatus::Retrying,
        );
    }

    /**
     * Drops what the agent left uncommitted. A branch the remote has (a PR was
     * opened, or `git ls-remote` finds it) is also reset to its pushed head so
     * local leftovers cannot be pushed; a branch that was never pushed has no
     * pushed head and only gets the clean.
     */
    private function prepareRetryTree(SandboxShell $shell): void
    {
        $workspacePath = IncusSandboxManager::workspacePath();
        $branchName = $this->task->branch_name ?? 'yak/' . $this->task->external_id;
        $escapedBranch = escapeshellarg($branchName);

        $isPushed = $this->task->pr_opened_at !== null
            || $shell->run('', "cd {$workspacePath} && git ls-remote --exit-code --heads origin {$escapedBranch}", timeout: 30)->exitCode() === 0;

        if ($isPushed) {
            $shell->run('', "cd {$workspacePath} && git fetch origin {$escapedBranch}", timeout: 60);
            $shell->run('', "cd {$workspacePath} && git reset --hard " . escapeshellarg("origin/{$branchName}"), timeout: 30);
        }

        $shell->run('', "cd {$workspacePath} && git clean -fd", timeout: 30);
    }
}
