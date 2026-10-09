<?php

namespace App\Console\Commands;

use App\Channels\Drone\PollCommand as DronePollCommand;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\TaskLogger;
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

        $tasks = YakTask::where('status', TaskStatus::AwaitingCi)
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

            $task->update([
                'status' => TaskStatus::Failed,
                'completed_at' => now(),
                'error_log' => $timeoutMessage,
            ]);

            TaskLogger::warning($task, 'Task failed — CI timeout');

            SendNotificationJob::dispatch(
                $task,
                NotificationType::Error,
                "{$timeoutMessage}. You can retry from the dashboard.",
            );

            $this->components->info("Timed out task #{$task->id}");
        }

        $this->components->info("Processed {$tasks->count()} task(s).");

        return self::SUCCESS;
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
            ->where('message', self::CI_RESUMED_LOG_MESSAGE)
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
