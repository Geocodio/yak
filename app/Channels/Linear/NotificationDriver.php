<?php

namespace App\Channels\Linear;

use App\Channels\Contracts\NotificationDriver as NotificationDriverContract;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationDriver implements NotificationDriverContract
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
            $this->sendAgentActivity($accessToken, $sessionId, $this->activityTypeForTask($task, $type), $body);

            $stage = SessionPlanStage::forTask($task, $type);
            if ($stage !== null) {
                $this->sendSessionPlan($accessToken, $task, $stage);
            }
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
     * Post an `action` activity to the Linear agent session timeline,
     * recording a concrete action taken and its optional outcome.
     * Used for milestones like "PR opened" where a result URL is available.
     */
    public function postAction(string $sessionId, string $action, ?string $result = null): void
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $sessionId === '') {
            return;
        }

        $this->sendAgentActivityContent($accessToken, $sessionId, [
            'type' => 'action',
            'action' => $action,
            'result' => $result,
        ]);
    }

    /**
     * Point the agent session at the task's dashboard page. Linear shows
     * external URLs as links on the session, and setting one also counts
     * as a response to a freshly created session.
     */
    public function setSessionDashboardUrl(YakTask $task): void
    {
        $sessionId = (string) $task->linear_agent_session_id;
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $sessionId === '') {
            return;
        }

        $this->sendSessionUpdate($accessToken, $sessionId, [
            'externalUrls' => [
                ['label' => 'Yak dashboard', 'url' => $this->taskDashboardLink($task)],
            ],
        ]);
    }

    /**
     * Add a link to the agent session without replacing the links it
     * already has.
     */
    public function addSessionExternalUrl(string $sessionId, string $label, string $url): void
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $sessionId === '' || $url === '') {
            return;
        }

        $this->sendSessionUpdate($accessToken, $sessionId, [
            'addedExternalUrls' => [
                ['label' => $label, 'url' => $url],
            ],
        ]);
    }

    /**
     * Replace the agent session plan with the checklist for the given
     * stage, derived from the task's status when no stage is given.
     */
    public function syncSessionPlan(YakTask $task, ?SessionPlanStage $stage = null): void
    {
        $stage ??= SessionPlanStage::forTask($task);
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $stage === null) {
            return;
        }

        $this->sendSessionPlan($accessToken, $task, $stage);
    }

    /**
     * Map Yak's NotificationType to one of Linear's agent activity
     * content types. Linear derives the session state from the last
     * activity: `thought` and `action` keep it active, `elicitation`
     * awaits input, `response` completes it and `error` fails it.
     */
    public function activityTypeFor(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::Result => 'response',
            NotificationType::Error, NotificationType::Expiry => 'error',
            NotificationType::Clarification => 'elicitation',
            default => 'thought',
        };
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
     * A task that already succeeded keeps its session complete: later
     * notices, such as a failed walkthrough render, go out as `response`
     * so they do not flip a finished session to active or error.
     */
    private function activityTypeForTask(YakTask $task, NotificationType $type): string
    {
        if ($task->status === TaskStatus::Success) {
            return 'response';
        }

        return $this->activityTypeFor($type);
    }

    private function sendSessionPlan(string $accessToken, YakTask $task, SessionPlanStage $stage): void
    {
        $sessionId = (string) $task->linear_agent_session_id;
        $plan = SessionPlan::build($task, $stage);
        if ($sessionId === '' || $plan === null) {
            return;
        }

        $this->sendSessionUpdate($accessToken, $sessionId, ['plan' => $plan]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function sendSessionUpdate(string $accessToken, string $sessionId, array $input): void
    {
        Http::withToken($accessToken)
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($id: String!, $input: AgentSessionUpdateInput!) { agentSessionUpdate(id: $id, input: $input) { success } }',
                'variables' => [
                    'id' => $sessionId,
                    'input' => $input,
                ],
            ]);
    }

    private function sendAgentActivity(string $accessToken, string $sessionId, string $type, string $body): void
    {
        $this->sendAgentActivityContent($accessToken, $sessionId, [
            'type' => $type,
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function sendAgentActivityContent(string $accessToken, string $sessionId, array $content): void
    {
        Http::withToken($accessToken)
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'mutation($input: AgentActivityCreateInput!) { agentActivityCreate(input: $input) { success } }',
                'variables' => [
                    'input' => [
                        'agentSessionId' => $sessionId,
                        'content' => $content,
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
            return $connection->freshAccessToken(app(OAuthService::class));
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
