<?php

namespace App\Channels\Slack;

use App\Channels\Contracts\NotificationDriver as NotificationDriverContract;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationType;
use App\Models\BranchDeployment;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationDriver implements NotificationDriverContract
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
        // initial acknowledgment — UserTracker::markSeen returns
        // true on the very first call per user, false thereafter.
        $firstTimeIntro = $type === NotificationType::Acknowledgment
            && $task->slack_user_id
            && UserTracker::markSeen((string) $task->slack_user_id);

        $blocks = BlockFormatter::blocks(
            $task,
            $type,
            $personalizedMessage,
            $dashboardUrl,
            firstTimeIntro: $firstTimeIntro,
        );
        $fallbackText = BlockFormatter::fallbackText($personalizedMessage);

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $task->slack_channel,
                'thread_ts' => $task->slack_thread_ts,
                'text' => $fallbackText,
                'blocks' => $blocks,
            ]);

        // If we just sent a clarification with click-to-answer buttons,
        // count it for the Interactivity health check. The check pairs
        // this counter with received-payload count to catch installs
        // where the Slack app has no Interactivity request URL.
        if ($type === NotificationType::Clarification && self::hasClarificationOptions($task)) {
            InteractivityTracker::recordSent();
        }

        $this->react($task, $type, $token);
    }

    /**
     * Send a direct message from the Yak bot to a Yak user. Posting with the
     * Slack user ID as the channel opens the bot's DM with that person, the
     * same call the welcome DM uses. Skips when Slack has no account for the
     * user's email; UserLookup logs that.
     */
    public function sendDirect(User $recipient, YakTask $task, NotificationType $type, string $message, string $headline): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            Log::channel('yak')->info('Direct message skipped, Slack bot token is not set', ['task_id' => $task->id]);

            return;
        }

        $slackUserId = app(UserLookup::class)->slackUserIdFor($recipient);

        if ($slackUserId === null) {
            return;
        }

        $response = Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $slackUserId,
                'text' => $headline,
                'blocks' => BlockFormatter::directMessageBlocks(
                    $task,
                    $type,
                    $headline,
                    $message,
                    $this->taskDashboardUrl($task),
                    $this->previewUrl($task),
                ),
            ]);

        if ($response->json('ok') !== true) {
            Log::channel('yak')->warning('Slack direct message was not delivered', [
                'task_id' => $task->id,
                'user_id' => $recipient->id,
                'error' => $response->json('error'),
            ]);
        }
    }

    /**
     * A preview can be opened once its branch deployment is running or
     * hibernated; a visit wakes a hibernated one.
     */
    private function previewUrl(YakTask $task): ?string
    {
        if ($task->branch_name === null) {
            return null;
        }

        $deployment = BranchDeployment::query()
            ->whereHas('repository', fn ($query) => $query->where('slug', $task->repo))
            ->where('branch_name', $task->branch_name)
            ->whereIn('status', [DeploymentStatus::Running->value, DeploymentStatus::Hibernated->value])
            ->first();

        return $deployment !== null ? 'https://' . $deployment->hostname : null;
    }

    private static function hasClarificationOptions(YakTask $task): bool
    {
        $options = $task->clarification_options;

        return is_array($options) && $options !== [];
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
     * Events that need a person (Clarification, Reminder, Result, Error,
     * Cancelled) mention the requester so Slack pushes a notification. On a
     * follow-up the person who replied in the thread is mentioned too.
     * Acknowledgment, Progress, Retry and Expiry stay silent.
     */
    private function prependMention(YakTask $task, NotificationType $type, string $message): string
    {
        if (! $this->shouldMentionRequester($type)) {
            return $message;
        }

        $mentions = collect([$task->slack_user_id, $task->slack_follow_up_user_id])
            ->filter(fn (?string $slackUserId): bool => $slackUserId !== null && $slackUserId !== '')
            ->unique()
            ->map(fn (string $slackUserId): string => "<@{$slackUserId}>")
            ->implode(' ');

        return $mentions === '' ? $message : "{$mentions} {$message}";
    }

    private function shouldMentionRequester(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::Clarification,
            NotificationType::Reminder,
            NotificationType::Result,
            NotificationType::Error,
            NotificationType::Cancelled => true,
            default => false,
        };
    }

    private function taskDashboardUrl(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }
}
