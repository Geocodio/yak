<?php

namespace App\Jobs;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Models\YakTask;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly YakTask $task,
        public readonly NotificationType $type,
        public readonly string $message,
    ) {
        $this->onQueue('default');
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('yak')->error(self::class . ' failed', [
            'task_id' => $this->task->id,
            'error' => $e?->getMessage() ?? 'Job failed without exception',
            'exception_class' => $e !== null ? get_class($e) : null,
        ]);
    }

    public function handle(ChannelRegistry $registry): void
    {
        TaskContext::set($this->task);

        try {
            $driver = $this->resolveDriver($registry);

            if ($driver === null) {
                return;
            }

            $personalizedMessage = YakPersonality::generate($this->type, $this->message);
            $driver->send($this->task, $this->type, $personalizedMessage);
        } finally {
            TaskContext::clear();
        }
    }

    private function resolveDriver(ChannelRegistry $registry): ?NotificationDriver
    {
        $source = (string) $this->task->source;
        $sourceChannel = $registry->for($source);

        if ($sourceChannel !== null && $sourceChannel->enabled()) {
            $driver = $sourceChannel->notificationDriver();

            if ($driver !== null) {
                return $driver;
            }
        }

        // Fallback: if the task has an open PR, notify via GitHub.
        if ($this->task->pr_url === null) {
            return null;
        }

        return $registry->for('github')?->notificationDriver();
    }
}
