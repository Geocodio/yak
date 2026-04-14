<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Enums\NotificationType;
use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use App\Services\LinearOAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinearNotificationDriver implements NotificationDriver
{
    private const GRAPHQL_ENDPOINT = 'https://api.linear.app/graphql';

    public function send(YakTask $task, NotificationType $type, string $message): void
    {
        $accessToken = $this->resolveAccessToken();
        $issueId = $this->resolveLinearIssueId($task);

        if ($accessToken === null || $issueId === '') {
            return;
        }

        $dashboardLink = $this->taskDashboardLink($task);
        $body = $this->formatComment($task, $type, $message, $dashboardLink);

        $this->postComment($accessToken, $issueId, $body);

        if ($type === NotificationType::Result || $type === NotificationType::Expiry) {
            $this->updateIssueState($accessToken, $issueId, $type);
        }
    }

    /**
     * Post a raw comment on the Linear issue associated with a task.
     * Used by jobs that want to post outside the standard notification
     * driver lifecycle (e.g. "PR created" announcements).
     */
    public function postIssueComment(YakTask $task, string $message): void
    {
        $accessToken = $this->resolveAccessToken();
        $issueId = $this->resolveLinearIssueId($task);

        if ($accessToken === null || $issueId === '') {
            return;
        }

        $this->postComment($accessToken, $issueId, $message);
    }

    /**
     * Move the Linear issue associated with a task to a specific
     * workflow state. Returns silently when no connection or issue UUID
     * is available.
     */
    public function setIssueState(YakTask $task, string $stateId): void
    {
        $accessToken = $this->resolveAccessToken();
        $issueId = $this->resolveLinearIssueId($task);

        if ($accessToken === null || $issueId === '' || $stateId === '') {
            return;
        }

        Http::withToken($accessToken)
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($issueId: String!, $stateId: String!) { issueUpdate(id: $issueId, input: { stateId: $stateId }) { success } }',
                'variables' => [
                    'issueId' => $issueId,
                    'stateId' => $stateId,
                ],
            ]);
    }

    /**
     * Return a fresh OAuth access token, or null if Linear isn't
     * connected / the connection was invalidated / refresh failed.
     */
    private function resolveAccessToken(): ?string
    {
        $connection = LinearOauthConnection::active();
        if ($connection === null) {
            return null;
        }

        try {
            return $connection->freshAccessToken(app(LinearOAuthService::class));
        } catch (LinearOAuthRefreshFailedException $e) {
            Log::warning('LinearNotificationDriver skipped: refresh failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve the Linear issue UUID for GraphQL calls.
     *
     * Tasks store a "LINEAR-ENG-123" identifier in external_id and keep
     * the UUID in the context metadata for API calls.
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

    private function postComment(string $accessToken, string $issueId, string $body): void
    {
        Http::withToken($accessToken)
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
    private function updateIssueState(string $accessToken, string $issueId, NotificationType $type): void
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

        Http::withToken($accessToken)
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
}
