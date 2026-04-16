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
        if ($accessToken === null) {
            return;
        }

        $sessionId = (string) $task->linear_agent_session_id;
        if ($sessionId !== '') {
            $dashboardLink = $this->taskDashboardLink($task);
            $body = "{$message}\n\n[View on Dashboard]({$dashboardLink})";
            $this->sendAgentActivity($accessToken, $sessionId, $this->mapActivityType($type), $body);
        }

        if ($type === NotificationType::Result || $type === NotificationType::Expiry) {
            $this->updateIssueState($accessToken, $task, $type);
        }
    }

    /**
     * Post a freeform agent activity without going through the
     * notification-type mapping. Used by the webhook controller's
     * `prompted` path and any ad-hoc session interactions.
     */
    public function postAgentActivity(string $sessionId, string $type, string $body): void
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $sessionId === '') {
            return;
        }

        $this->sendAgentActivity($accessToken, $sessionId, $type, $body);
    }

    /**
     * Move the Linear issue associated with a task to a specific
     * workflow state. Returns silently when no connection or issue UUID
     * is available.
     */
    public function setIssueState(YakTask $task, string $stateId): void
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $stateId === '') {
            return;
        }

        $issueId = $this->resolveLinearIssueId($task);
        if ($issueId === '') {
            return;
        }

        $this->postIssueState($accessToken, $issueId, $stateId);
    }

    /**
     * Attach a URL to the Linear issue so it persists as a first-
     * class attachment on the issue (visible under the Attachments
     * section, findable long after the agent session ends). Used for
     * research report links.
     */
    public function createIssueAttachment(YakTask $task, string $title, string $url, ?string $subtitle = null): void
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $url === '' || $title === '') {
            return;
        }

        $issueId = $this->resolveLinearIssueId($task);
        if ($issueId === '') {
            return;
        }

        $input = [
            'issueId' => $issueId,
            'title' => $title,
            'url' => $url,
        ];
        if ($subtitle !== null && $subtitle !== '') {
            $input['subtitle'] = $subtitle;
        }

        Http::withToken($accessToken)
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($input: AttachmentCreateInput!) { attachmentCreate(input: $input) { success } }',
                'variables' => ['input' => $input],
            ]);
    }

    /**
     * Map Yak's NotificationType to one of Linear's agent activity
     * content types: `thought` (progress, retries), `response` (final
     * result), `error` (failures / expiry), `elicitation` (clarification
     * prompt — unreachable on Linear today but mapped for completeness).
     */
    private function mapActivityType(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::Result => 'response',
            NotificationType::Error, NotificationType::Expiry => 'error',
            NotificationType::Clarification => 'elicitation',
            default => 'thought',
        };
    }

    private function sendAgentActivity(string $accessToken, string $sessionId, string $type, string $body): void
    {
        Http::withToken($accessToken)
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($input: AgentActivityCreateInput!) { agentActivityCreate(input: $input) { success } }',
                'variables' => [
                    'input' => [
                        'agentSessionId' => $sessionId,
                        'content' => [
                            'type' => $type,
                            'body' => $body,
                        ],
                    ],
                ],
            ]);
    }

    private function updateIssueState(string $accessToken, YakTask $task, NotificationType $type): void
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

        $issueId = $this->resolveLinearIssueId($task);
        if ($issueId === '') {
            return;
        }

        $this->postIssueState($accessToken, $issueId, $stateId);
    }

    private function postIssueState(string $accessToken, string $issueId, string $stateId): void
    {
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
     * Resolve the Linear issue UUID for GraphQL calls. Tasks stash the
     * UUID in context metadata; fall back to external_id if for some
     * reason context is empty.
     */
    private function resolveLinearIssueId(YakTask $task): string
    {
        $context = json_decode((string) $task->context, true);
        if (is_array($context) && ! empty($context['linear_issue_id'])) {
            return (string) $context['linear_issue_id'];
        }

        return (string) $task->external_id;
    }

    private function taskDashboardLink(YakTask $task): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/tasks/{$task->id}";
    }
}
