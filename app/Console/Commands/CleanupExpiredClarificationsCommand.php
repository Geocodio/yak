<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\TaskLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:cleanup')]
#[Description('Expire unanswered clarifications and remind people about questions open for a working day')]
class CleanupExpiredClarificationsCommand extends Command
{
    public function handle(): int
    {
        $this->expireClarifications();
        $this->sendReminders();

        return self::SUCCESS;
    }

    private function expireClarifications(): void
    {
        $tasks = YakTask::where('status', TaskStatus::AwaitingClarification)
            ->whereNotNull('clarification_expires_at')
            ->where('clarification_expires_at', '<=', now())
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => TaskStatus::Expired,
                'completed_at' => now(),
            ]);

            TaskLogger::warning($task, 'Task expired');

            SendNotificationJob::dispatch(
                $task,
                NotificationType::Expiry,
                'Clarification expired, no response received',
            );

            $this->components->info("Expired task #{$task->id}");
        }

        $this->components->info("Expired {$tasks->count()} task(s).");
    }

    /**
     * Expiry runs first, so a question that expires in this pass gets no
     * reminder. Clearing the reminder time makes it a one-time notice.
     */
    private function sendReminders(): void
    {
        $tasks = YakTask::where('status', TaskStatus::AwaitingClarification)
            ->whereNotNull('clarification_reminder_at')
            ->where('clarification_reminder_at', '<=', now())
            ->get();

        foreach ($tasks as $task) {
            $task->update(['clarification_reminder_at' => null]);

            TaskLogger::info($task, 'Clarification reminder sent');

            $numberedOptions = collect($task->clarification_options ?? [])
                ->map(fn (string $option, int $index): string => ($index + 1) . '. ' . $option)
                ->implode("\n");

            SendNotificationJob::dispatch(
                $task,
                NotificationType::Reminder,
                rtrim('Still waiting on an answer to my question. It closes ' . ($task->clarification_expires_at?->diffForHumans() ?? 'soon') . " if nobody replies.\n{$numberedOptions}"),
            );
        }

        $this->components->info("Sent {$tasks->count()} reminder(s).");
    }
}
