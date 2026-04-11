<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Http;

class GitHubNotificationDriver implements NotificationDriver
{
    public function __construct(private readonly GitHubAppService $gitHubAppService) {}

    public function send(YakTask $task, NotificationType $type, string $message): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $repo = (string) $task->repo;

        if ($installationId === 0 || $repo === '' || $task->pr_url === null) {
            return;
        }

        $prNumber = $this->extractPrNumber($task->pr_url);

        if ($prNumber === null) {
            return;
        }

        $dashboardLink = $this->taskDashboardLink($task);
        $body = $this->formatComment($type, $message, $dashboardLink);

        $token = $this->gitHubAppService->getInstallationToken($installationId);

        Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repo}/issues/{$prNumber}/comments", [
                'body' => $body,
            ]);
    }

    private function formatComment(NotificationType $type, string $message, string $dashboardLink): string
    {
        $prefix = match ($type) {
            NotificationType::Acknowledgment => '🤖 Task acknowledged.',
            NotificationType::Progress => '⏳ Progress:',
            NotificationType::Clarification => '❓ Clarification needed:',
            NotificationType::Retry => '🔄 Retry:',
            NotificationType::Result => '✅ Result:',
            NotificationType::Expiry => '⏰ Expired:',
            NotificationType::Error => '🚨 Error:',
        };

        return "{$prefix} {$message}\n\n[View on Dashboard]({$dashboardLink})";
    }

    private function extractPrNumber(string $prUrl): ?int
    {
        if (preg_match('#/pull/(\d+)#', $prUrl, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function taskDashboardLink(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }
}
