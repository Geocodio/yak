<?php

namespace App\Http\Controllers\Webhooks;

use App\Drivers\LinearInputDriver;
use App\Enums\NotificationType;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use App\Services\LinearIssueFetcher;
use App\Services\RepoDetector;
use App\Services\TaskLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinearWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.linear.webhook_secret'),
            'Linear-Signature',
            prefix: '', // Linear sends the raw HMAC-SHA256 digest with no prefix
        );

        // Only handle Issue update events. Subscribe to "Issue" events
        // in Linear's webhook settings.
        if ($request->input('type') !== 'Issue' || $request->input('action') !== 'update') {
            return response()->json(['ok' => true]);
        }

        $connection = $this->resolveConnection($request);

        // Only proceed when the issue has just been assigned to the Yak app
        if ($connection === null || ! $this->isAssignedToYak($request, $connection)) {
            return response()->json(['ok' => true]);
        }

        return $this->handleAssignment($request);
    }

    /**
     * Locate the OAuth connection this webhook belongs to. Linear includes
     * the workspace id on every event payload.
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
     * Determine if this update transitions the issue's assignee to the
     * Yak app (i.e., someone just delegated the issue to Yak).
     */
    private function isAssignedToYak(Request $request, LinearOauthConnection $connection): bool
    {
        $yakActorId = $connection->yakActorId();

        if ($yakActorId === null) {
            return false;
        }

        // Only fire on events that carry an assignee transition.
        if (! $request->has('updatedFrom.assigneeId')) {
            return false;
        }

        $currentAssigneeId = (string) $request->input('data.assignee.id', '');
        $previousAssigneeId = (string) $request->input('updatedFrom.assigneeId', '');

        // Must now be assigned to Yak.
        if ($currentAssigneeId !== $yakActorId) {
            return false;
        }

        // Ignore unrelated updates to already-assigned issues.
        return $previousAssigneeId !== $yakActorId;
    }

    /**
     * Handle an issue being assigned to Yak — create task and dispatch RunYakJob.
     */
    private function handleAssignment(Request $request): JsonResponse
    {
        $driver = new LinearInputDriver;
        $description = $driver->parse($request);

        // Don't create duplicate tasks for the same Linear issue
        $existingTask = YakTask::where('source', 'linear')
            ->where('external_id', $description->externalId)
            ->first();

        if ($existingTask !== null) {
            return response()->json(['ok' => true]);
        }

        $detector = new RepoDetector;
        $detection = $detector->detect($description);

        $repoSlug = $detection->resolved
            ? $detection->firstRepository()->slug
            : ($description->repository ?? 'unknown');

        $linearIssueId = (string) ($description->metadata['linear_issue_id'] ?? '');
        $enriched = $this->enrichBody($description->body, $linearIssueId);

        $task = YakTask::create([
            'source' => 'linear',
            'repo' => $repoSlug,
            'external_id' => $description->externalId,
            'external_url' => $description->metadata['linear_issue_url'] ?? null,
            'description' => $enriched,
            'mode' => $description->metadata['mode'] ?? 'fix',
            'context' => json_encode([
                'title' => $description->metadata['title'] ?? '',
                'description' => $description->metadata['description'] ?? '',
                'linear_issue_id' => $description->metadata['linear_issue_id'] ?? '',
                'linear_issue_identifier' => $description->metadata['linear_issue_identifier'] ?? '',
                'linear_issue_url' => $description->metadata['linear_issue_url'] ?? '',
            ]),
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'linear', 'repo' => $repoSlug]);
        SendNotificationJob::dispatch($task, NotificationType::Acknowledgment, "Issue: {$description->body}");

        RunYakJob::dispatch($task);

        return response()->json(['ok' => true]);
    }

    /**
     * Append comments, attachments, sub-issues, and assignment metadata
     * fetched from Linear to the original webhook body. The webhook
     * payload only carries title + description, but the agent benefits
     * from the full conversation. Failures are non-fatal — fall back to
     * the bare body.
     */
    private function enrichBody(string $body, string $linearIssueId): string
    {
        if ($linearIssueId === '') {
            return $body;
        }

        $issue = app(LinearIssueFetcher::class)->fetch($linearIssueId);

        if ($issue === null) {
            return $body;
        }

        $rendered = app(LinearIssueFetcher::class)->renderAsMarkdown($issue);

        return $rendered === '' ? $body : "{$body}\n\n---\n\n{$rendered}";
    }
}
