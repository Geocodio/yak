<?php

namespace App\Console\Commands;

use App\Channels\Drone\PollCommand as DronePollCommand;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\TaskLogger;
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

    public function handle(): int
    {
        $timeoutMinutes = (int) config('yak.ci_timeout_minutes', 30);

        $tasks = YakTask::where('status', TaskStatus::AwaitingCi)
            ->where('updated_at', '<=', now()->subMinutes($timeoutMinutes))
            ->get();

        foreach ($tasks as $task) {
            // If CI never reported at all, it's likely misconfigured — skip CI and
            // advance to PR creation instead of failing the task.
            if ($task->attempts <= 1 && $this->ciNeverReported($task)) {
                TaskLogger::warning($task, "No CI results received after {$timeoutMinutes} minutes — skipping CI and creating PR");

                ProcessCIResultJob::dispatch($task, passed: true);

                $this->components->info("Skipped CI for task #{$task->id} (no CI results received)");

                continue;
            }

            $task->update([
                'status' => TaskStatus::Failed,
                'completed_at' => now(),
                'error_log' => "CI timed out after {$timeoutMinutes} minutes",
            ]);

            TaskLogger::warning($task, 'Task failed — CI timeout');

            SendNotificationJob::dispatch(
                $task,
                NotificationType::Error,
                "CI timed out after {$timeoutMinutes} minutes. You can retry from the dashboard.",
            );

            $this->components->info("Timed out task #{$task->id}");
        }

        $this->components->info("Processed {$tasks->count()} task(s).");

        return self::SUCCESS;
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
