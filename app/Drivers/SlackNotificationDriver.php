<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

class SlackNotificationDriver implements NotificationDriver
{
    public function send(YakTask $task, NotificationType $type, string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $task->slack_channel || ! $task->slack_thread_ts) {
            return;
        }

        $dashboardLink = $this->taskDashboardLink($task);
        $text = $this->formatMessage($task, $type, $message, $dashboardLink);

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $task->slack_channel,
                'thread_ts' => $task->slack_thread_ts,
                'text' => $text,
            ]);
    }

    private function formatMessage(YakTask $task, NotificationType $type, string $message, string $dashboardLink): string
    {
        return $this->markdownToSlack($message) . "\n{$dashboardLink}";
    }

    /**
     * Convert common Markdown formatting to Slack mrkdwn.
     */
    private function markdownToSlack(string $text): string
    {
        // **bold** → *bold*
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '*$1*', $text);

        // [text](url) → <url|text>
        $text = (string) preg_replace('/\[(.+?)\]\((.+?)\)/', '<$2|$1>', $text);

        return $text;
    }

    private function taskDashboardLink(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }
}
