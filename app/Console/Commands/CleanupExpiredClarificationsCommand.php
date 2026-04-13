<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\TaskLogger;
use App\Services\YakPersonality;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:cleanup')]
#[Description('Expire tasks awaiting clarification past their expiry date')]
class CleanupExpiredClarificationsCommand extends Command
{
    public function handle(): int
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

            $message = YakPersonality::generate(
                NotificationType::Expiry,
                'Clarification expired — no response received',
            );
            SendNotificationJob::dispatch($task, NotificationType::Expiry, $message);

            $this->components->info("Expired task #{$task->id}");
        }

        $this->components->info("Expired {$tasks->count()} task(s).");

        return self::SUCCESS;
    }
}
