<?php

namespace App\Jobs;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\NotificationDriver;
use App\Channels\Slack\NotificationDriver as SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Facades\Telemetry;
use App\Models\User;
use App\Models\YakTask;
use App\Services\DirectMessageRecipients;
use App\Services\YakPersonality;
use App\Support\TaskContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single place Yak sends task notifications from. The message goes to
 * the channel the task came from first, then as a Slack direct message to
 * whoever DirectMessageRecipients names.
 */
class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [1, 5, 10];

    private const int HEADLINE_TITLE_LENGTH = 80;

    /**
     * @param  bool  $personalize  False when the message is already in the Yak voice, for example a research answer with a report link appended after the rewrite.
     * @param  User|null  $actingUser  Who caused the event; a Cancelled notice names them.
     * @param  bool  $directMessagesOnly  True when the source channel was already told directly, as Linear's webhook does within its ten-second limit.
     */
    public function __construct(
        public readonly YakTask $task,
        public readonly NotificationType $type,
        public readonly string $message,
        public readonly bool $personalize = true,
        public readonly ?User $actingUser = null,
        public readonly bool $directMessagesOnly = false,
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
            if ($this->isCancelledByStarter()) {
                return;
            }

            $driver = $this->directMessagesOnly ? null : $this->resolveDriver($registry);
            $recipients = app(DirectMessageRecipients::class)->for($this->task, $this->type);

            if ($driver === null && $recipients === []) {
                return;
            }

            $personalizedMessage = $this->personalize
                ? $this->keepNumberedOptions(YakPersonality::generate($this->type, $this->message))
                : $this->message;

            if ($driver !== null) {
                Telemetry::time('notification.sent', fn () => $driver->send($this->task, $this->type, $personalizedMessage), [
                    'type' => $this->type->value,
                    'channel' => strtolower(explode('\\', $driver::class)[2] ?? 'unknown'),
                    'length' => mb_strlen($personalizedMessage),
                ], task: $this->task);
            }

            foreach ($recipients as $recipient) {
                $this->sendDirectMessage($recipient, $personalizedMessage);
            }
        } finally {
            TaskContext::clear();
        }
    }

    /**
     * The person who started a task gets no notice about their own cancel.
     */
    private function isCancelledByStarter(): bool
    {
        return $this->type === NotificationType::Cancelled
            && $this->actingUser !== null
            && $this->actingUser->id === $this->task->started_by_user_id;
    }

    /**
     * A failed direct message is logged, not thrown, because a retry of the
     * job would post to the source channel a second time.
     */
    private function sendDirectMessage(User $recipient, string $personalizedMessage): void
    {
        try {
            Telemetry::time(
                'notification.sent',
                fn () => app(SlackNotificationDriver::class)->sendDirect($recipient, $this->task, $this->type, $personalizedMessage, $this->headline()),
                [
                    'type' => $this->type->value,
                    'channel' => 'slack_dm',
                    'length' => mb_strlen($personalizedMessage),
                ],
                task: $this->task,
            );
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Direct message failed', [
                'task_id' => $this->task->id,
                'user_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The fixed first line of a direct message and its Slack fallback text,
     * so a lock screen shows what happened and to which task.
     */
    private function headline(): string
    {
        $title = Str::limit($this->task->headline(), self::HEADLINE_TITLE_LENGTH);
        $repository = (string) $this->task->repo;
        $isSetup = $this->task->mode === TaskMode::Setup;

        return match ($this->type) {
            NotificationType::Clarification => "Yak needs an answer: {$title}",
            NotificationType::Reminder => "Still waiting for your answer: {$title}",
            NotificationType::Result => match (true) {
                $isSetup => "Setup finished: {$repository}",
                $this->task->pr_url !== null && $this->task->parent_task_id !== null => "PR updated: {$title}",
                $this->task->pr_url !== null => "PR ready: {$title}",
                default => "Answer ready: {$title}",
            },
            NotificationType::Error => match (true) {
                $isSetup => "Setup failed: {$repository}",
                $this->task->status === TaskStatus::Success => "Video failed: {$title}",
                default => "Failed: {$title}",
            },
            NotificationType::Cancelled => 'Cancelled by ' . ($this->actingUser->name ?? 'someone else') . ": {$title}",
            default => $title,
        };
    }

    /**
     * Channels without buttons rely on the numbered option lines in a
     * clarification or reminder message. When the personality rewrite dropped or
     * reworded any of them, the original lines are appended so the user
     * can still reply with a number.
     */
    private function keepNumberedOptions(string $personalizedMessage): string
    {
        if (! in_array($this->type, [NotificationType::Clarification, NotificationType::Reminder], true)) {
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

    /**
     * The driver of the channel the task came from. A task posts to GitHub
     * only when it came from GitHub; other sources never fall back to a pull
     * request comment.
     */
    private function resolveDriver(ChannelRegistry $registry): ?NotificationDriver
    {
        $sourceChannel = $registry->for((string) $this->task->source);

        if ($sourceChannel === null || ! $sourceChannel->enabled()) {
            return null;
        }

        return $sourceChannel->notificationDriver();
    }
}
