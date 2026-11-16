<?php

namespace App\Jobs;

use App\Contracts\SandboxShell;
use App\Enums\TaskRunKind;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\ClaimsTask;
use App\Jobs\Concerns\FinishesResearchFollowUpRuns;
use App\Jobs\Concerns\HandlesAgentJobFailure;
use App\Jobs\Concerns\NotifiesSourceOfFailure;
use App\Jobs\Concerns\ReportsResearchToSource;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Jobs\Middleware\ClaimsTaskAtomically;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Support\TaskContext;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
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
    use FinishesResearchFollowUpRuns;
    use HandlesAgentJobFailure;
    use InteractsWithQueue;
    use NotifiesSourceOfFailure;
    use Queueable;
    use ReportsResearchToSource;
    use StartsThreadTurns;

    /** Where the previous report sits in the sandbox for the agent to read. */
    private const PREVIOUS_REPORT_PATH = '.yak-artifacts/previous-research.html';

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
            $this->runFollowUp();
        } finally {
            $this->finishParkingForQuestions();
            TaskContext::clear();
        }
    }

    private function runFollowUp(): void
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

        TaskLogger::info($this->task, 'Picked up by worker -- research follow-up');

        // Research is read-only, so the thread's working tree is left as it is.
        $hasPreviousReport = false;

        $this->startThreadTurn(
            $repository,
            TaskRunKind::FollowUp,
            function () use (&$hasPreviousReport): string {
                return $this->promptFor(fn (): string => YakPromptBuilder::researchFollowUpPrompt(
                    (string) $this->task->description,
                    $this->previousSummary(),
                    $hasPreviousReport,
                ));
            },
            function (SandboxShell $shell) use (&$hasPreviousReport): void {
                $hasPreviousReport = $this->stagePreviousReport($shell);
            },
            $this->task->attachments->all(),
        );
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
    private function stagePreviousReport(SandboxShell $sandbox): bool
    {
        $artifact = $this->task->latestResearchArtifact();

        if ($artifact === null || ! Storage::disk('artifacts')->exists($artifact->disk_path)) {
            return false;
        }

        $workspacePath = IncusSandboxManager::workspacePath();
        $remotePath = "{$workspacePath}/" . self::PREVIOUS_REPORT_PATH;

        $sandbox->run('', 'mkdir -p ' . escapeshellarg(dirname($remotePath)), timeout: 10, asRoot: true);
        $sandbox->pushFile('', Storage::disk('artifacts')->path($artifact->disk_path), $remotePath);
        $sandbox->run('', 'chown -R yak:yak ' . escapeshellarg(dirname($remotePath)), timeout: 10, asRoot: true);

        return true;
    }
}
