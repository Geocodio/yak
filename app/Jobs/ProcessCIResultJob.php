<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Console\Commands\TimeoutAwaitingCiCommand;
use App\DataTransferObjects\TaskStop;
use App\Enums\HeldEventKind;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Facades\Telemetry;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\DefaultBranchCi;
use App\Services\PullRequestFinisher;
use App\Services\Push\PullRequestPush;
use App\Services\TaskLogger;
use App\Services\Tasks\StopNotifier;
use App\Services\Teleport\HeldEvents;
use App\Services\Threads\PullRequests\ThreadPullRequestSync;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCIResultJob implements ShouldQueue
{
    use Queueable;

    /**
     * The log line written for every CI result, whatever channel reported it.
     *
     * yak:timeout-ci matches on this exact message to tell "CI is not wired up
     * for this repo" apart from "CI is wired up but slow", so it must stay in
     * sync with TimeoutAwaitingCiCommand::CI_REPORT_MESSAGES.
     */
    public const RESULT_LOG_MESSAGE = 'CI result received';

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly bool $passed,
        public readonly ?string $output = null,
        public readonly ?string $commitSha = null,
    ) {
        $this->onQueue('default');
    }

    public function failed(?\Throwable $e): void
    {
        $errorMessage = $e?->getMessage() ?? 'Job failed without exception';

        Log::channel('yak')->error(self::class . ' failed', [
            'task_id' => $this->task->id,
            'error' => $errorMessage,
            'exception_class' => $e !== null ? get_class($e) : null,
        ]);

        $fresh = $this->task->fresh();

        // Don't disturb a task that's already settled. A Failed task was
        // reported by whoever failed it, so a second Error would repeat it.
        // For anything else, typically AwaitingCi or Running when PR creation
        // throws, flip to Failed so the UI stops displaying a misleading
        // transient state and the user can retry.
        $settled = [TaskStatus::Success, TaskStatus::Cancelled, TaskStatus::Failed, TaskStatus::AwaitingCoOwner];
        if ($fresh === null || in_array($fresh->status, $settled, true)) {
            return;
        }

        $fresh->update([
            'status' => TaskStatus::Failed,
            'completed_at' => now(),
        ]);

        TaskLogger::error($fresh, 'Task failed during CI result processing', ['error' => $errorMessage]);

        try {
            StopNotifier::notify($fresh, TaskStop::yakSideProblem($errorMessage));
        } catch (\Throwable $notifyError) {
            Log::channel('yak')->warning(self::class . ' failed() notification errored', [
                'task_id' => $this->task->id,
                'error' => $notifyError->getMessage(),
            ]);
        }
    }

    /**
     * States in which a CI result is still meaningful.
     *
     * AwaitingCi is the normal one. Running covers repos with ci_system=none,
     * where the agent jobs dispatch a synthetic pass to reach PR creation.
     * Retrying covers a result landing while a retry is being spun up.
     *
     * @var array<int, TaskStatus>
     */
    private const ACTIONABLE_STATUSES = [
        TaskStatus::AwaitingCi,
        TaskStatus::Running,
        TaskStatus::Retrying,
    ];

    public function handle(): void
    {
        TaskContext::set($this->task);

        try {
            // CI can report long after yak:timeout-ci has already given up on
            // the task, and GitHub fires one check_suite event per suite, so
            // the same branch can produce several of these. Acting on a task
            // that has already settled means an agent run nobody is waiting
            // for or a duplicate PR, so drop the result.
            if ($this->shouldReviveAfterCiTimeout()) {
                $this->reviveAfterCiTimeout();
            }

            if (! in_array($this->task->status, self::ACTIONABLE_STATUSES, true)) {
                TaskLogger::info($this->task, 'Late CI result ignored', [
                    'passed' => $this->passed,
                    'status' => $this->task->status->value,
                ]);

                return;
            }

            if (! $this->passed && app(HeldEvents::class)->holdIfDeviceOwned($this->task, HeldEventKind::CiFailed, 'CI failed on the pull request', Str::limit((string) $this->output, 500), ['commit_sha' => $this->commitSha])) {
                return;
            }

            TaskLogger::info($this->task, self::RESULT_LOG_MESSAGE, ['passed' => $this->passed]);

            $this->recordCiResult();
            app(ThreadPullRequestSync::class)->recordPrimaryCiStatus($this->task, $this->passed ? 'passing' : 'failing');

            if (! $this->passed && $this->deferToDefaultBranch()) {
                return;
            }

            $this->pushCiResult();

            if ($this->passed) {
                $this->handleGreenPath();
            } elseif ($this->task->hasCiRetryLeft()) {
                $this->handleRetry();
            } else {
                $this->handleFinalFailure();
            }
        } finally {
            TaskContext::clear();
        }
    }

    /**
     * A result is worth acting on after yak:timeout-ci gave up when the task
     * failed for exactly that reason, no PR exists yet, and the result belongs
     * to the commit the branch points at now. Results for older commits, or
     * whose commit is unknown or cannot be confirmed, stay ignored.
     */
    private function shouldReviveAfterCiTimeout(): bool
    {
        $task = $this->task;

        if ($task->status !== TaskStatus::Failed
            || $task->pr_url !== null
            || ! str_starts_with((string) $task->error_log, TimeoutAwaitingCiCommand::TIMEOUT_ERROR_PREFIX)) {
            return false;
        }

        if ($this->commitSha === null) {
            return false;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0 || $task->branch_name === null) {
            return false;
        }

        try {
            $repository = Repository::where('slug', $task->repo)->first();

            return $repository !== null
                && app(GitHubAppService::class)->getBranchHeadSha($installationId, $repository->github_full_name, $task->branch_name) === $this->commitSha;
        } catch (\Throwable $e) {
            Log::warning('Could not confirm the branch head for a late CI result', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Failed can only move to Retrying, and Retrying can move to AwaitingCi,
     * so the revival takes both legal steps. The attempt count is untouched,
     * so a red result spends the normal CI retry budget.
     */
    private function reviveAfterCiTimeout(): void
    {
        $this->task->update(['status' => TaskStatus::Retrying]);
        $this->task->update([
            'status' => TaskStatus::AwaitingCi,
            'completed_at' => null,
            'error_log' => null,
        ]);

        TaskLogger::info($this->task, 'Late CI result accepted after CI timeout', ['passed' => $this->passed]);
    }

    /**
     * `ci.result` carries how long CI took after the agent's last push,
     * measured from the most recent run's agent_finished_at.
     */
    private function recordCiResult(): void
    {
        $lastRun = TaskRun::query()
            ->where('yak_task_id', $this->task->id)
            ->whereNotNull('agent_finished_at')
            ->latest('agent_finished_at')
            ->first();

        Telemetry::record('ci.result', [
            'passed' => $this->passed,
            'attempts' => (int) $this->task->attempts,
            'synthetic' => $this->output === null && $this->passed && $lastRun !== null
                && $lastRun->agent_finished_at !== null
                && $lastRun->agent_finished_at->diffInSeconds(now()) < 5,
        ], task: $this->task, durationMs: $lastRun?->agent_finished_at !== null
            ? max(0, now()->getTimestampMs() - $lastRun->agent_finished_at->getTimestampMs())
            : null);
    }

    /**
     * A failure Yak will retry stays quiet; the person hears about the one that ends the run.
     */
    private function pushCiResult(): void
    {
        $pullRequestPush = app(PullRequestPush::class);

        if ($this->passed) {
            $pullRequestPush->ciPassed($this->task);
        } elseif (! $this->task->hasCiRetryLeft()) {
            $pullRequestPush->ciFailed($this->task);
        }
    }

    private function handleGreenPath(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        // Artifacts are persisted upstream in RunYakJob/RetryYakJob/
        // RunFollowUpJob right after SandboxArtifactCollector pulls
        // them out of the sandbox — that way the walkthrough video +
        // screenshots show up on the thread page and Remotion rendering
        // runs in parallel with Drone CI instead of sequentially.

        $loc = $this->countLinesOfCode($repository);
        $isLargeChange = $loc > $repository->settings()->largeChangeLines();

        CreatePullRequestJob::dispatchSync($this->task, $isLargeChange);

        $this->task->refresh();

        if ($this->task->status === TaskStatus::AwaitingCoOwner) {
            return;
        }

        if ($this->task->pr_url === null) {
            Log::warning('No PR was opened after a green CI result', ['task_id' => $this->task->id]);

            return;
        }

        app(PullRequestFinisher::class)->finish($this->task);
    }

    /**
     * GitHub-started work already has its pull request comment from
     * CreatePullRequestJob, and GitHub notifies the people on that pull
     * request. A second comment for the same event would notify them twice.
     */
    private function notifyUnlessGitHubStarted(NotificationType $type, string $message): void
    {
        app(PullRequestFinisher::class)->notifyUnlessGitHubStarted($this->task, $type, $message);
    }

    /**
     * A red result may not be the branch's fault. When every failing check also fails on the default
     * branch, the task parks until the default branch passes them. When the branch is behind a default
     * branch that passes them, the branch is updated once per default branch head. Either way no
     * agent run and no attempt is spent. Anything unreadable or conflicting goes to the normal retry.
     */
    private function deferToDefaultBranch(): bool
    {
        $task = $this->task;
        $repository = Repository::where('slug', $task->repo)->first();

        if ($repository === null || $task->branch_name === null || (int) config('yak.channels.github.installation_id') === 0) {
            return false;
        }

        $defaultBranchCi = app(DefaultBranchCi::class);

        try {
            $comparison = $defaultBranchCi->compare($repository, $task->branch_name);

            if ($comparison['branchFailing'] === []) {
                return false;
            }

            if (array_diff($comparison['branchFailing'], $comparison['defaultFailing']) === []) {
                $this->parkOnDefaultBranch($comparison['branchFailing'], $repository);

                return true;
            }

            if ($comparison['defaultHasChecks']
                && array_intersect($comparison['branchFailing'], $comparison['defaultFailing']) === []
                && $defaultBranchCi->isBehindDefaultBranch($task, $repository)
                && $defaultBranchCi->claimDefaultBranchHead($task, $repository) !== null
                && $defaultBranchCi->syncBranch($task, $repository) === true) {
                $defaultBranchCi->resumeAwaitingCi($task);

                return true;
            }
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Could not compare the failing checks with the default branch', ['task_id' => $task->id, 'error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * @param  list<string>  $checks
     */
    private function parkOnDefaultBranch(array $checks, Repository $repository): void
    {
        if ($this->task->status !== TaskStatus::AwaitingCi) {
            $this->task->update(['status' => TaskStatus::AwaitingCi]);
        }

        $this->task->update([
            'waiting_on_default_branch_at' => now(),
            'default_branch_wait_checks' => $checks,
        ]);

        TaskLogger::info($this->task, 'Failing checks also fail on the default branch -- waiting for it to pass', ['checks' => $checks]);

        $list = implode(', ', $checks);
        $verb = count($checks) === 1 ? 'fails' : 'fail';
        $message = YakPersonality::generate(NotificationType::Progress, "CI failed on {$this->task->branch_name}, but {$list} also {$verb} on {$repository->default_branch}. Not retrying; I'll update the branch once they pass there.");
        SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
    }

    private function handleRetry(): void
    {
        $this->task->update([
            'status' => TaskStatus::Retrying,
            'attempts' => $this->task->attempts + 1,
        ]);

        $this->notifyUnlessGitHubStarted(NotificationType::Retry, 'CI failed, retrying');

        RetryYakJob::dispatch($this->task, $this->output);
    }

    private function handleFinalFailure(): void
    {
        $repository = Repository::where('slug', $this->task->repo)->firstOrFail();

        $failureSummary = $this->output ?? 'CI failed after maximum attempts';
        $this->task->update([
            'status' => TaskStatus::Failed,
            'completed_at' => now(),
        ]);

        StopNotifier::notify($this->task, TaskStop::ciStillFailing(
            $this->task->attempts - $this->task->attempts_at_manual_retry,
            self::failedCheckNames($failureSummary),
            $failureSummary,
        ));

        TaskLogger::error($this->task, 'Task failed', ['error' => $failureSummary]);
    }

    /**
     * The check names from the `## <name> (<url>)` headings of the CI output.
     *
     * @return list<string>
     */
    public static function failedCheckNames(string $failureSummary): array
    {
        preg_match_all('/^## (.+?)(?: \(https?:\/\/\S+\))?\s*$/m', $failureSummary, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function countLinesOfCode(Repository $repository): int
    {
        if ($this->task->branch_name === null) {
            return 0;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            return 0;
        }

        try {
            $compare = app(GitHubAppService::class)->compareBranches(
                $installationId,
                $repository->github_full_name,
                $repository->default_branch,
                $this->task->branch_name,
            );

            return $compare['loc_changed'];
        } catch (\Throwable $e) {
            Log::warning('Failed to compute LOC via GitHub API', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
