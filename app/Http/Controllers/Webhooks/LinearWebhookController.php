<?php

namespace App\Http\Controllers\Webhooks;

use App\Drivers\LinearInputDriver;
use App\Enums\NotificationType;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
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

        // Only handle Issue update events. Note: Linear's "IssueLabel" resource
        // type is for label entity changes (rename/delete), not labels added to
        // issues. Subscribe to "Issue" events in Linear's webhook settings.
        if ($request->input('type') !== 'Issue' || $request->input('action') !== 'update') {
            return response()->json(['ok' => true]);
        }

        // Only proceed when labels were changed and yak label is being added
        if (! $this->isYakLabelAdded($request)) {
            return response()->json(['ok' => true]);
        }

        return $this->handleYakLabel($request);
    }

    /**
     * Determine if the `yak` label was added (not removed) in this webhook event.
     */
    private function isYakLabelAdded(Request $request): bool
    {
        /** @var list<string> $updatedFrom */
        $updatedFrom = $request->input('updatedFrom.labelIds', []);

        /** @var list<array{id?: string, name?: string}> $currentLabels */
        $currentLabels = $request->input('data.labels', []);

        $currentLabelNames = array_map(
            fn (array $label): string => strtolower($label['name'] ?? ''),
            $currentLabels,
        );

        // yak must be in current labels
        if (! in_array('yak', $currentLabelNames, true)) {
            return false;
        }

        // Check that the label set actually changed (labels were updated)
        $currentLabelIds = array_map(
            fn (array $label): string => $label['id'] ?? '',
            $currentLabels,
        );

        // If updatedFrom.labelIds differs from current label IDs, labels changed
        // If updatedFrom is not present, this isn't a label change event
        if (! $request->has('updatedFrom.labelIds')) {
            return false;
        }

        // Ensure yak was not already present before this update
        // (i.e., this is a label addition, not a different field update on an already-labeled issue)
        sort($updatedFrom);
        sort($currentLabelIds);

        return $updatedFrom !== $currentLabelIds;
    }

    /**
     * Handle yak label being added — create task and dispatch RunYakJob.
     */
    private function handleYakLabel(Request $request): JsonResponse
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

        $task = YakTask::create([
            'source' => 'linear',
            'repo' => $repoSlug,
            'external_id' => $description->externalId,
            'external_url' => $description->metadata['linear_issue_url'] ?? null,
            'description' => $description->body,
            'mode' => $description->metadata['mode'] ?? 'fix',
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'linear', 'repo' => $repoSlug]);
        SendNotificationJob::dispatch($task, NotificationType::Acknowledgment, "Issue: {$description->body}");

        RunYakJob::dispatch($task);

        return response()->json(['ok' => true]);
    }
}
