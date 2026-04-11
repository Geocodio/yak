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
        return match ($type) {
            NotificationType::Acknowledgment => "🤖 Task acknowledged. {$message}\n{$dashboardLink}",
            NotificationType::Progress => "⏳ {$message}\n{$dashboardLink}",
            NotificationType::Clarification => "❓ Clarification needed: {$message}\n{$dashboardLink}",
            NotificationType::Retry => "🔄 {$message}\n{$dashboardLink}",
            NotificationType::Result => "✅ {$message}\n{$dashboardLink}",
            NotificationType::Expiry => "⏰ {$message}\n{$dashboardLink}",
            NotificationType::Error => "🚨 {$message}\n{$dashboardLink}",
        };
    }

    private function taskDashboardLink(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }
}
