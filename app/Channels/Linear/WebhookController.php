<?php

namespace App\Channels\Linear;

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\RepoDetector;
use App\Services\TaskLogger;
use App\Services\YakPersonality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.linear.webhook_secret'),
            'Linear-Signature',
            prefix: '',
        );

        // Replay protection: reject stale events when Linear includes a timestamp.
        $timestampMs = $request->input('webhookTimestamp');
        if ($timestampMs !== null && abs(now()->getTimestampMs() - (int) $timestampMs) > 60_000) {
            return response()->json(['ok' => true, 'skipped' => 'stale webhook']);
        }

        // Idempotency: skip a delivery we've already processed (Linear retries).
        $deliveryId = (string) $request->header('Linear-Delivery', '');
        if ($deliveryId !== '' && ! Cache::add("linear-delivery:{$deliveryId}", true, now()->addMinutes(10))) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate delivery']);
        }

        if ($request->header('Linear-Event') !== 'AgentSessionEvent') {
            return response()->json(['ok' => true]);
        }

        if ($this->resolveConnection($request) === null) {
            return response()->json(['ok' => true]);
        }

        return match ((string) $request->input('action')) {
            'created' => $this->handleCreated($request),
            'prompted' => $this->handlePrompted($request),
            default => response()->json(['ok' => true]),
        };
    }

    /**
     * Resolve the OAuth connection this webhook belongs to via the
     * workspace id Linear stamps on every payload.
     */
    private function resolveConnection(Request $request): ?LinearOauthConnection
    {
        $workspaceId = (string) $request->input('organizationId', '');
        if ($workspaceId === '') {
            return null;
        }

        return LinearOauthConnection::activeForWorkspace($workspaceId);
    }

    /**
     * Handle the delegation of a Linear issue to Yak: create the task,
     * post a synchronous acknowledgement activity (Linear's 10-second
     * SLA), then dispatch the run job.
     */
    private function handleCreated(Request $request): JsonResponse
    {
        $description = app(InputDriver::class)->parse($request);

        $existing = YakTask::where('source', 'linear')
            ->where('external_id', $description->externalId)
            ->first();

        if ($existing !== null) {
            return response()->json(['ok' => true]);
        }

        $detection = app(RepoDetector::class)->detect($description);
        $repoSlug = $detection->resolved
            ? $detection->firstRepository()->slug
            : ($description->repository ?? 'unknown');

        $task = YakTask::create([
            'source' => 'linear',
            'repo' => $repoSlug,
            'external_id' => $description->externalId,
            'external_url' => $description->metadata['linear_issue_url'] ?? null,
            'description' => $description->body,
            'mode' => $description->metadata['mode'] ?? 'fix',
            'linear_agent_session_id' => $description->metadata['linear_agent_session_id'] ?? null,
            'context' => json_encode([
                'title' => $description->metadata['title'] ?? '',
                'description' => $description->metadata['description'] ?? '',
                'linear_issue_id' => $description->metadata['linear_issue_id'] ?? '',
                'linear_issue_identifier' => $description->metadata['linear_issue_identifier'] ?? '',
                'linear_issue_url' => $description->metadata['linear_issue_url'] ?? '',
                'linear_agent_session_id' => $description->metadata['linear_agent_session_id'] ?? '',
            ]),
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'linear', 'repo' => $repoSlug]);

        // Post synchronously so Linear sees an activity well within the
        // 10-second SLA from `created`. Run the personality agent with
        // a 2-second timeout so the bot sounds like Yak from the first
        // message — on timeout or API error we fall back to the static
        // template, which still keeps the voice consistent with later
        // async messages. Skip the async ack dispatch since we've
        // already posted one sync.
        $ackMessage = YakPersonality::generateWithTimeout(
            NotificationType::Acknowledgment,
            "Issue: {$description->body}",
            timeoutSeconds: 2,
        );
        app(NotificationDriver::class)
            ->send($task, NotificationType::Acknowledgment, $ackMessage);

        $startedStateId = (string) config('yak.channels.linear.started_state_id');
        if ($startedStateId !== '') {
            app(NotificationDriver::class)->setIssueState($task, $startedStateId);
        }

        $this->dispatchAgentJob($task);

        return response()->json(['ok' => true]);
    }

    /**
     * Dispatch the right agent job for the task's mode. Research tasks
     * go through ResearchYakJob (read-only, produces artifacts); every
     * other mode goes through RunYakJob (writes code, pushes a branch,
     * waits on CI).
     */
    private function dispatchAgentJob(YakTask $task): void
    {
        if ($task->mode === TaskMode::Research) {
            ResearchYakJob::dispatch($task);

            return;
        }

        RunYakJob::dispatch($task);
    }

    /**
     * Handle follow-up messages inside an existing agent session. Routes
     * the prompt to the correct handler based on task state:
     *
     * - stop signal → cancel the task
     * - AwaitingClarification → dispatch ClarificationReplyJob
     * - open PR → create a chained follow-up via FollowUpTaskFactory
     * - merged/closed → post a polite decline
     * - unknown session → no-op (200 OK)
     *
     * Always posts an immediate thought ack within the 10-second SLA.
     */
    private function handlePrompted(Request $request): JsonResponse
    {
        $sessionId = (string) $request->input('agentSession.id', '');

        if ($sessionId === '') {
            return response()->json(['ok' => true]);
        }

        // Immediate ack within the 10-second SLA — use same personality
        // call as handleCreated (2-second timeout, falls back to template).
        $ackMessage = YakPersonality::generateWithTimeout(
            NotificationType::Acknowledgment,
            'Follow-up received',
            timeoutSeconds: 2,
        );
        app(NotificationDriver::class)->postAgentActivity($sessionId, type: 'thought', body: $ackMessage);

        $task = YakTask::where('linear_agent_session_id', $sessionId)->latest()->first();

        if ($task === null) {
            return response()->json(['ok' => true]);
        }

        // Read the message body from either the nested content shape
        // (agentActivity.content.body) or the flat shape (agentActivity.body).
        $message = (string) ($request->input('agentActivity.content.body')
            ?? $request->input('agentActivity.body')
            ?? '');
        $signal = (string) ($request->input('agentActivity.signal') ?? '');

        if ($signal === 'stop') {
            $cancellable = [
                TaskStatus::Pending,
                TaskStatus::Running,
                TaskStatus::AwaitingCi,
                TaskStatus::AwaitingClarification,
                TaskStatus::Retrying,
            ];

            if (in_array($task->status, $cancellable, strict: true)) {
                $task->update(['status' => TaskStatus::Cancelled, 'completed_at' => now()]);
            }

            app(NotificationDriver::class)->postAgentActivity($sessionId, type: 'response', body: 'Stopped.');

            return response()->json(['ok' => true]);
        }

        if ($task->status === TaskStatus::AwaitingClarification) {
            ClarificationReplyJob::dispatch($task, $message);

            return response()->json(['ok' => true]);
        }

        if ($task->prIsOpen()) {
            app(FollowUpTaskFactory::class)->create($task, $message, 'linear');

            return response()->json(['ok' => true]);
        }

        app(NotificationDriver::class)->postAgentActivity(
            $sessionId,
            type: 'response',
            body: "This PR is already merged or closed — mention me in a fresh issue and I'll pick it up.",
        );

        return response()->json(['ok' => true]);
    }
}
