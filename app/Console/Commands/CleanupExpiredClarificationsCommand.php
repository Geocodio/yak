<?php

namespace App\Console\Commands;

use App\DataTransferObjects\TaskStop;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\ClarificationMessage;
use App\Services\TaskLogger;
use App\Services\Tasks\StopNotifier;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

            StopNotifier::notify(
                $task,
                TaskStop::unansweredQuestion($this->askedAt($task), 'Clarification expired, no response received'),
                NotificationType::Expiry,
            );

            $this->components->info("Expired task #{$task->id}");
        }

        $this->components->info("Expired {$tasks->count()} task(s).");
    }

    /**
     * The latest round's asked_at, or the expiry minus the clarification TTL
     * for a question whose latest round has no readable asked time.
     */
    private function askedAt(YakTask $task): CarbonInterface
    {
        $rounds = $task->clarificationRounds();
        $recorded = $rounds === [] ? null : ($rounds[array_key_last($rounds)]['asked_at'] ?? null);

        try {
            $askedAt = is_string($recorded) && $recorded !== '' ? Carbon::parse($recorded) : null;
        } catch (InvalidFormatException) {
            $askedAt = null;
        }

        if ($askedAt !== null) {
            return $askedAt;
        }

        return $task->clarification_expires_at->subDays((int) config('yak.clarification_ttl_days', 7));
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

            $hasQuestions = $task->pendingClarificationQuestions() !== [];
            $message = $hasQuestions
                ? ClarificationMessage::reminder($task)
                : rtrim('Still waiting on an answer to my question. It closes ' . ($task->clarification_expires_at?->diffForHumans() ?? 'soon') . " if nobody replies.\n{$numberedOptions}");

            SendNotificationJob::dispatch($task, NotificationType::Reminder, $message, personalize: ! $hasQuestions);
        }

        $this->components->info("Sent {$tasks->count()} reminder(s).");
    }
}
