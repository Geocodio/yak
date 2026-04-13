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

#[Signature('yak:timeout-ci')]
#[Description('Fail tasks stuck in awaiting_ci past the configured timeout')]
class TimeoutAwaitingCiCommand extends Command
{
    public function handle(): int
    {
        $timeoutMinutes = (int) config('yak.ci_timeout_minutes', 30);

        $tasks = YakTask::where('status', TaskStatus::AwaitingCi)
            ->where('updated_at', '<=', now()->subMinutes($timeoutMinutes))
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => TaskStatus::Failed,
                'completed_at' => now(),
                'error_log' => "CI timed out after {$timeoutMinutes} minutes",
            ]);

            TaskLogger::warning($task, 'Task failed — CI timeout');

            $message = YakPersonality::generate(
                NotificationType::Error,
                "CI timed out after {$timeoutMinutes} minutes. You can retry from the dashboard.",
            );
            SendNotificationJob::dispatch($task, NotificationType::Error, $message);

            $this->components->info("Timed out task #{$task->id}");
        }

        $this->components->info("Timed out {$tasks->count()} task(s).");

        return self::SUCCESS;
    }
}
