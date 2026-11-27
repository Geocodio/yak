<?php

namespace App\Console\Commands;

use App\Channels\Drone\PollCommand as DronePollCommand;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\DataTransferObjects\TaskStop;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\DefaultBranchCi;
use App\Services\TaskLogger;
use App\Services\Tasks\StopNotifier;
use App\Services\Tasks\TaskActions;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:timeout-ci')]
#[Description('Auto-advance or fail tasks stuck in awaiting_ci past the configured timeout')]
class TimeoutAwaitingCiCommand extends Command
{
    /**
     * The exact log messages that mean "a CI result arrived for this task".
     *
     * @var array<int, string>
     */
    public const CI_REPORT_MESSAGES = [
        ProcessCIResultJob::RESULT_LOG_MESSAGE,
        DronePollCommand::RESULT_LOG_MESSAGE,
    ];

    /**
     * The start of every error_log this command writes. ProcessCIResultJob
     * matches on it to recognise a task that failed only because CI was slow.
     */
    public const TIMEOUT_ERROR_PREFIX = 'CI timed out after ';

    public const CI_RESUMED_LOG_MESSAGE = 'Retry requested after a CI timeout, checking CI again';

    public const STILL_RUNNING_LOG_MESSAGE = 'CI still running on GitHub, waiting';

    public function handle(): int
    {
        $timeoutMinutes = (int) config('yak.ci_timeout_minutes', 30);
        $maxWaitMinutes = max($timeoutMinutes, (int) config('yak.ci_max_wait_minutes', 180));

        $this->failExpiredDefaultBranchWaits();

        $tasks = YakTask::where('status', TaskStatus::AwaitingCi)
            ->whereNull('waiting_on_default_branch_at')
            ->where('updated_at', '<=', now()->subMinutes($timeoutMinutes))
            ->get();

        foreach ($tasks as $task) {
            $isCiStillPending = $this->isCiStillPending($task);
            $waitStartedAt = $this->waitStartedAt($task);

            if ($isCiStillPending && $waitStartedAt->gt(now()->subMinutes($maxWaitMinutes))) {
                $this->logStillRunningOnce($task, $waitStartedAt);

                $this->components->info("Task #{$task->id} still has CI running on GitHub, waiting");

                continue;
            }

            $timeoutMessage = $isCiStillPending
                ? self::TIMEOUT_ERROR_PREFIX . "{$maxWaitMinutes} minutes (still queued or running on GitHub, the maximum wait)"
                : self::TIMEOUT_ERROR_PREFIX . "{$timeoutMinutes} minutes";

            // If CI never reported at all, it's likely misconfigured — skip CI and
            // advance to PR creation instead of failing the task.
            if (! $isCiStillPending && $task->attempts <= 1 && $this->ciNeverReported($task)) {
                TaskLogger::warning($task, "No CI results received after {$timeoutMinutes} minutes — skipping CI and creating PR");

                ProcessCIResultJob::dispatch($task, passed: true);

                $this->components->info("Skipped CI for task #{$task->id} (no CI results received)");

                continue;
            }

            // A branch whose newest commit passed every check has nothing to wait for, so it gets its pull request.
            $greenHeadSha = $isCiStillPending ? null : $this->greenHeadSha($task);

            if ($greenHeadSha !== null) {
                TaskLogger::info($task, 'CI timeout reached but every check on the branch passed, opening the pull request', ['head_sha' => $greenHeadSha]);

                ProcessCIResultJob::dispatch($task, passed: true, commitSha: $greenHeadSha);

                $this->components->info("Opened the pull request for task #{$task->id} (CI green on GitHub)");

                continue;
            }

            $task->update([
                'status' => TaskStatus::Failed,
                'completed_at' => now(),
            ]);

            TaskLogger::warning($task, 'Task failed — CI timeout');

            StopNotifier::notify($task, TaskStop::ciNeverReported(
                $isCiStillPending ? $maxWaitMinutes : $timeoutMinutes,
                $timeoutMessage,
            ));

            $this->components->info("Timed out task #{$task->id}");
        }

        $this->components->info("Processed {$tasks->count()} task(s).");

        return self::SUCCESS;
    }

    /**
     * A task parked on the default branch waits for it to pass, not for CI, so only its own limit ends the wait.
     */
    private function failExpiredDefaultBranchWaits(): void
    {
        $expired = YakTask::where('status', TaskStatus::AwaitingCi)
            ->where('waiting_on_default_branch_at', '<=', now()->subHours(DefaultBranchCi::WAIT_LIMIT_HOURS))
            ->get();

        foreach ($expired as $task) {
            $checks = (array) $task->default_branch_wait_checks;

            $task->update(['status' => TaskStatus::Failed, 'completed_at' => now()]);

            TaskLogger::warning($task, 'Task failed -- the default branch kept failing the same checks', ['checks' => $checks]);

            StopNotifier::notify($task, TaskStop::waitedOnDefaultBranch(DefaultBranchCi::WAIT_LIMIT_HOURS, $checks));

            $this->components->info("Gave up on task #{$task->id} waiting for the default branch");
        }
    }

    /**
     * Ask GitHub whether the task's newest pushed commit still has an
     * unfinished workflow run. Any failure to find out, and any CI system
     * other than GitHub Actions, counts as "not pending" so the plain
     * timeout applies.
     */
    private function isCiStillPending(YakTask $task): bool
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $repository = Repository::where('slug', $task->repo)->first();

        if ($installationId === 0
            || $task->branch_name === null
            || $repository === null
            || $repository->settings()->ciSystem() !== 'github_actions') {
            return false;
        }

        try {
            return app(GitHubAppService::class)->hasUnfinishedWorkflowRunsForBranch(
                $installationId,
                $repository->github_full_name,
                $task->branch_name,
            );
        } catch (\Throwable $e) {
            TaskLogger::warning($task, 'Could not ask GitHub whether CI is still running', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The branch's newest commit when GitHub reports at least one workflow run
     * on it and every run passed. Null when any run failed or is unfinished,
     * when there are none, and when GitHub cannot say.
     */
    private function greenHeadSha(YakTask $task): ?string
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $repository = Repository::where('slug', $task->repo)->first();

        if ($installationId === 0
            || $task->branch_name === null
            || $repository === null
            || $repository->settings()->ciSystem() !== 'github_actions') {
            return null;
        }

        try {
            $runs = app(GitHubAppService::class)->latestCommitWorkflowRunsForBranch($installationId, $repository->github_full_name, $task->branch_name);
        } catch (\Throwable) {
            return null;
        }

        $hasOnlyPassingRuns = $runs !== [] && array_filter(
            $runs,
            fn (array $run): bool => GitHubAppService::isUnfinishedWorkflowRun($run) || ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true),
        ) === [];

        return $hasOnlyPassingRuns ? $runs[0]['head_sha'] : null;
    }

    /**
     * When the task last handed its branch to CI: the end of the latest agent
     * run or a dashboard retry that went back to waiting on CI, whichever is
     * later, or the task's last update when neither is recorded.
     */
    private function waitStartedAt(YakTask $task): CarbonInterface
    {
        $lastAgentFinish = TaskRun::query()
            ->where('yak_task_id', $task->id)
            ->whereNotNull('agent_finished_at')
            ->max('agent_finished_at');

        $lastCiResume = $task->logs()
            ->whereIn('message', [self::CI_RESUMED_LOG_MESSAGE, TaskActions::RECOVERY_RERUN_LOG_MESSAGE, DefaultBranchCi::RESYNCED_LOG_MESSAGE])
            ->max('created_at');

        $candidates = array_filter([$lastAgentFinish, $lastCiResume]);

        if ($candidates === []) {
            return $task->updated_at ?? now();
        }

        return Carbon::parse(max($candidates));
    }

    private function logStillRunningOnce(YakTask $task, CarbonInterface $waitStartedAt): void
    {
        $alreadyLogged = $task->logs()
            ->where('message', self::STILL_RUNNING_LOG_MESSAGE)
            ->where('created_at', '>=', $waitStartedAt)
            ->exists();

        if (! $alreadyLogged) {
            TaskLogger::info($task, self::STILL_RUNNING_LOG_MESSAGE);
        }
    }

    /**
     * Check if CI ever reported any result for this task.
     *
     * If no CI webhook or poll ever landed, the task has none of the log lines
     * a CI result writes — meaning CI is likely not configured for this
     * repo/branch, and the task should advance to PR creation rather than fail.
     *
     * Matched exactly, never with a wildcard: the task log also carries the
     * agent's own tool-call descriptions, and a label as ordinary as
     * "Run new tests with CI env" used to satisfy a `LIKE '%CI %'` probe and
     * push a task down the hard-fail branch it was meant to be spared.
     */
    private function ciNeverReported(YakTask $task): bool
    {
        return ! $task->logs()
            ->whereIn('message', self::CI_REPORT_MESSAGES)
            ->exists();
    }
}
