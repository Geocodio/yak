<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

class LinearNotificationDriver implements NotificationDriver
{
    private const GRAPHQL_ENDPOINT = 'https://api.linear.app/graphql';

    public function send(YakTask $task, NotificationType $type, string $message): void
    {
        $apiKey = $this->getApiKey();
        $issueId = $this->resolveLinearIssueId($task);

        if ($apiKey === '' || $issueId === '') {
            return;
        }

        $dashboardLink = $this->taskDashboardLink($task);
        $body = $this->formatComment($task, $type, $message, $dashboardLink);

        $this->postComment($apiKey, $issueId, $body);

        if ($type === NotificationType::Result || $type === NotificationType::Expiry) {
            $this->updateIssueState($apiKey, $issueId, $type);
        }
    }

    /**
     * Resolve the Linear issue UUID for GraphQL calls.
     *
     * Historical tasks stored the UUID directly in external_id. Newer tasks
     * store a "LINEAR-ENG-123" identifier in external_id and keep the UUID in
     * the context metadata, so fall back to that when present.
     */
    private function resolveLinearIssueId(YakTask $task): string
    {
        $context = json_decode((string) $task->context, true);
        if (is_array($context) && ! empty($context['linear_issue_id'])) {
            return (string) $context['linear_issue_id'];
        }

        return (string) $task->external_id;
    }

    private function formatComment(YakTask $task, NotificationType $type, string $message, string $dashboardLink): string
    {
        return "{$message}\n\n[View on Dashboard]({$dashboardLink})";
    }

    private function postComment(string $apiKey, string $issueId, string $body): void
    {
        Http::withHeaders(['Authorization' => $apiKey])
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($issueId: String!, $body: String!) { commentCreate(input: { issueId: $issueId, body: $body }) { success } }',
                'variables' => [
                    'issueId' => $issueId,
                    'body' => $body,
                ],
            ]);
    }

    /**
     * Update issue state based on notification type.
     * Uses configured state IDs from yak.channels.linear config.
     */
    private function updateIssueState(string $apiKey, string $issueId, NotificationType $type): void
    {
        $stateConfigKey = match ($type) {
            NotificationType::Result => 'done_state_id',
            NotificationType::Expiry => 'cancelled_state_id',
            default => null,
        };

        if ($stateConfigKey === null) {
            return;
        }

        $stateId = (string) config("yak.channels.linear.{$stateConfigKey}");

        if ($stateId === '') {
            return;
        }

        Http::withHeaders(['Authorization' => $apiKey])
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($issueId: String!, $stateId: String!) { issueUpdate(id: $issueId, input: { stateId: $stateId }) { success } }',
                'variables' => [
                    'issueId' => $issueId,
                    'stateId' => $stateId,
                ],
            ]);
    }

    private function taskDashboardLink(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }

    private function getApiKey(): string
    {
        return (string) config('yak.channels.linear.api_key');
    }
}
