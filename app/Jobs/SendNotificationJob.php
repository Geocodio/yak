<?php

namespace App\Jobs;

use App\Channel;
use App\Channels\Contracts\NotificationDriver;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Drivers\GitHubNotificationDriver;
use App\Drivers\SlackNotificationDriver;
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

    public function handle(): void
    {
        TaskContext::set($this->task);

        try {
            $driver = $this->resolveDriver();

            if ($driver === null) {
                return;
            }

            $personalizedMessage = YakPersonality::generate($this->type, $this->message);
            $driver->send($this->task, $this->type, $personalizedMessage);
        } finally {
            TaskContext::clear();
        }
    }

    private function resolveDriver(): ?NotificationDriver
    {
        $source = (string) $this->task->source;

        $driver = $this->driverForSource($source);

        if ($driver !== null && $this->isSourceChannelEnabled($source)) {
            return $driver;
        }

        return $this->fallbackDriver();
    }

    private function driverForSource(string $source): ?NotificationDriver
    {
        return match ($source) {
            'slack' => new SlackNotificationDriver,
            'linear' => new LinearNotificationDriver,
            default => null,
        };
    }

    private function isSourceChannelEnabled(string $source): bool
    {
        return (new Channel($source))->enabled();
    }

    private function fallbackDriver(): ?NotificationDriver
    {
        if ($this->task->pr_url === null) {
            return null;
        }

        return app(GitHubNotificationDriver::class);
    }
}
