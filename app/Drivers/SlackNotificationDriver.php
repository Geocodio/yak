<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Models\YakTask;
use App\Support\SlackBlockFormatter;
use App\Support\SlackUserTracker;
use Illuminate\Support\Facades\Http;

class SlackNotificationDriver implements NotificationDriver
{
    /**
     * Maps notification types to the emoji reaction we apply on the
     * originating @yak message. Gives users a glanceable status signal
     * on their own message without having to open the thread. Uses
     * additive (stacking) reactions — we never remove, so the message
     * shows the full history (eyes → construction → check / x).
     *
     * @var array<string, string>
     */
    private const REACTION_BY_TYPE = [
        'acknowledgment' => 'eyes',
        'progress' => 'construction',
        'result' => 'white_check_mark',
        'error' => 'x',
        'expiry' => 'x',
    ];

    public function send(YakTask $task, NotificationType $type, string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $task->slack_channel || ! $task->slack_thread_ts) {
            return;
        }

        $dashboardUrl = $this->taskDashboardUrl($task);
        $personalizedMessage = $this->prependMention($task, $type, $message);

        // Show the first-time intro once per Slack user, only on the
        // initial acknowledgment — SlackUserTracker::markSeen returns
        // true on the very first call per user, false thereafter.
        $firstTimeIntro = $type === NotificationType::Acknowledgment
            && $task->slack_user_id
            && SlackUserTracker::markSeen((string) $task->slack_user_id);

        $blocks = SlackBlockFormatter::blocks(
            $task,
            $type,
            $personalizedMessage,
            $dashboardUrl,
            firstTimeIntro: $firstTimeIntro,
        );
        $fallbackText = SlackBlockFormatter::fallbackText($personalizedMessage);

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $task->slack_channel,
                'thread_ts' => $task->slack_thread_ts,
                'text' => $fallbackText,
                'blocks' => $blocks,
            ]);

        $this->react($task, $type, $token);
    }

    /**
     * Apply a status reaction to the originating @yak message.
     * Best-effort — Slack returns `already_reacted` when the emoji is
     * already set, which we ignore.
     */
    private function react(YakTask $task, NotificationType $type, string $token): void
    {
        $emoji = self::REACTION_BY_TYPE[$type->value] ?? null;
        $messageTs = (string) ($task->slack_message_ts ?? '');

        if ($emoji === null || $messageTs === '' || ! $task->slack_channel) {
            return;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/reactions.add', [
                'channel' => $task->slack_channel,
                'timestamp' => $messageTs,
                'name' => $emoji,
            ]);
    }

    /**
     * For status-changing notifications (Clarification/Result/Error/
     * Expiry) prepend the requester's Slack mention so they get a push
     * notification. Progress/Acknowledgment/Retry skip this — those are
     * noisy enough without re-pinging for every tick.
     */
    private function prependMention(YakTask $task, NotificationType $type, string $message): string
    {
        $userId = (string) ($task->slack_user_id ?? '');

        if ($userId === '' || ! $this->shouldMentionRequester($type)) {
            return $message;
        }

        return "<@{$userId}> {$message}";
    }

    private function shouldMentionRequester(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::Clarification,
            NotificationType::Result,
            NotificationType::Error,
            NotificationType::Expiry => true,
            default => false,
        };
    }

    private function taskDashboardUrl(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }
}
