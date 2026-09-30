<?php

namespace App\Jobs;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Facades\Telemetry;
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

            $personalizedMessage = $this->keepNumberedOptions(
                YakPersonality::generate($this->type, $this->message),
            );

            Telemetry::time('notification.sent', fn () => $driver->send($this->task, $this->type, $personalizedMessage), [
                'type' => $this->type->value,
                'channel' => strtolower(explode('\\', $driver::class)[2] ?? 'unknown'),
                'length' => mb_strlen($personalizedMessage),
            ], task: $this->task);
        } finally {
            TaskContext::clear();
        }
    }

    /**
     * Channels without buttons rely on the numbered option lines in a
     * clarification message. When the personality rewrite dropped or
     * reworded any of them, the original lines are appended so the user
     * can still reply with a number.
     */
    private function keepNumberedOptions(string $personalizedMessage): string
    {
        if ($this->type !== NotificationType::Clarification) {
            return $personalizedMessage;
        }

        if (preg_match_all('/^\d+\. .+$/m', $this->message, $matches) < 1) {
            return $personalizedMessage;
        }

        $missing = array_filter(
            $matches[0],
            fn (string $line): bool => ! str_contains($personalizedMessage, $line),
        );

        if ($missing === []) {
            return $personalizedMessage;
        }

        return rtrim($personalizedMessage) . "\n\n" . implode("\n", $matches[0]);
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

        // Dashboard tasks are UI-only; never fall back to a public PR comment.
        if ($this->task->source === 'dashboard') {
            return null;
        }

        // Fallback: if the task has an open PR, notify via GitHub.
        if ($this->task->pr_url === null) {
            return null;
        }

        return $registry->for('github')?->notificationDriver();
    }
}
