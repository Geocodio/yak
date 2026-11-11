<?php

namespace App\Jobs;

use App\Contracts\SandboxShell;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\FinishesFollowUpRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ResumesAgentOnExistingBranch;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\Middleware\PreventBranchOverlap;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskLogger;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class RunFollowUpJob implements ShouldQueue
{
    use AsksClarifyingQuestions;
    use FinishesFollowUpRuns;
    use HandlesAgentJobFailure;
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
            $this->runFollowUp();
        } finally {
            $this->finishParkingForQuestions();
            TaskContext::clear();
        }
    }

    private function runFollowUp(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        // started_at is what makes the run visible in the conversation
        // thread (see ThreadBuilder) -- without it a follow-up leaves no
        // trace, whether it succeeds or fails.
        $this->task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
            // Recorded so a drain interruption can tell this apart from a
            // fresh RunYakJob run -- yak:resume-interrupted-tasks must never
            // resume a follow-up as a plain RunYakJob, which would get a
            // fresh branch and lose its follow-up context.
            'claimed_job_class' => self::class,
        ]);
        TaskLogger::info($this->task, 'Picked up by worker -- follow-up');

        if ($this->task->branch_name === null) {
            $this->handleError('Follow-up task has no branch to push to.');

            return;
        }

        $branchName = $this->task->branch_name;

        $this->startThreadTurn(
            $repository,
            TaskRunKind::FollowUp,
            fn (): string => $this->promptFor(fn (): string => YakPromptBuilder::followUpPrompt((string) $this->task->description)),
            fn (SandboxShell $shell) => $this->prepareExistingBranch($shell, '', $repository, $branchName),
            $this->task->attachments->all(),
            statusWhileWaiting: TaskStatus::Pending,
        );
    }
}
