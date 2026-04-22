<?php

namespace App\Channels\Linear;

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use App\Services\RepoDetector;
use App\Services\TaskLogger;
use App\Services\YakPersonality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Handle follow-up messages inside an existing agent session. Yak
     * does not currently support multi-turn Linear conversations —
     * respond with a polite error so the session surfaces the guidance.
     * Runs the message through the personality agent (timed) so the
     * voice stays consistent with the rest of the session.
     */
    private function handlePrompted(Request $request): JsonResponse
    {
        $sessionId = (string) $request->input('agentSession.id', '');

        if ($sessionId === '') {
            return response()->json(['ok' => true]);
        }

        $context = "I can't continue this conversation inside Linear. To adjust the task, comment on the pull request or mention me in a fresh Linear issue.";

        $body = YakPersonality::generateWithTimeout(
            NotificationType::Error,
            $context,
            timeoutSeconds: 2,
        );

        app(NotificationDriver::class)->postAgentActivity(
            $sessionId,
            type: 'error',
            body: $body,
        );

        return response()->json(['ok' => true]);
    }
}
