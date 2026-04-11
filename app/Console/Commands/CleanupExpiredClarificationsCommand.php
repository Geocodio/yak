<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
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

            SendNotificationJob::dispatch(
                $task,
                NotificationType::Expiry,
                'Closing this -- mention me again if you still need it',
            );

            $this->components->info("Expired task #{$task->id}");
        }

        $this->components->info("Expired {$tasks->count()} task(s).");

        return self::SUCCESS;
    }
}
