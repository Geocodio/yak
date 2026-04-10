<?php

namespace App\Http\Controllers\Webhooks;

use App\Drivers\SentryFilter;
use App\Drivers\SentryInputDriver;
use App\Http\Controllers\Controller;
use App\Jobs\RunYakJob;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SentryWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->verifySentrySignature($request);

        // Only handle triggered issue alert events
        if ($request->input('action') !== 'triggered') {
            return response()->json(['ok' => true]);
        }

        /** @var array{id?: string|int, title?: string, culprit?: string, count?: string|int, firstSeen?: string, userCount?: int, seerActionability?: string, project?: array{slug?: string}} $issue */
        $issue = $request->input('data.issue', []);

        $tags = $this->extractTagKeys($request);

        // Must have yak-eligible tag
        if (! in_array('yak-eligible', $tags, true)) {
            return response()->json(['ok' => true]);
        }

        $hasPriorityTag = in_array('yak-priority', $tags, true);

        // Apply filtering rules
        $rejection = SentryFilter::rejectionReason(
            culprit: (string) ($issue['culprit'] ?? ''),
            title: (string) ($issue['title'] ?? ''),
            actionability: (string) ($issue['seerActionability'] ?? 'not_actionable'),
            eventCount: (int) ($issue['count'] ?? 0),
            hasPriorityTag: $hasPriorityTag,
            minActionability: (string) config('yak.channels.sentry.min_actionability', 'medium'),
            minEvents: (int) config('yak.channels.sentry.min_events', 5),
        );

        if ($rejection !== null) {
            return response()->json(['ok' => true, 'filtered' => $rejection]);
        }

        // Parse the payload into a task description
        $driver = new SentryInputDriver;
        $description = $driver->parse($request);

        // Repo must be resolved (sentry_project mapped to an active repository)
        if ($description->repository === null) {
            return response()->json(['ok' => true, 'filtered' => 'unknown_project']);
        }

        // Deduplication: same external_id + repo = conflict
        $existingTask = YakTask::where('external_id', $description->externalId)
            ->where('repo', $description->repository)
            ->first();

        if ($existingTask !== null) {
            return response()->json(['error' => 'duplicate'], 409);
        }

        $task = YakTask::create([
            'source' => 'sentry',
            'repo' => $description->repository,
            'external_id' => $description->externalId,
            'description' => $description->body,
            'mode' => 'fix',
        ]);

        RunYakJob::dispatch($task);

        return response()->json(['ok' => true, 'task_id' => $task->id], 201);
    }

    /**
     * Verify the Sentry webhook HMAC-SHA256 signature.
     *
     * Sentry sends a raw hex digest in the Sentry-Hook-Signature header (no prefix).
     */
    private function verifySentrySignature(Request $request): void
    {
        $signature = $request->header('Sentry-Hook-Signature', '');
        $secret = (string) config('yak.channels.sentry.webhook_secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }
    }

    /**
     * Extract tag key names from the event's tags array.
     *
     * @return list<string>
     */
    private function extractTagKeys(Request $request): array
    {
        /** @var list<array{key?: string, value?: string}> $tags */
        $tags = $request->input('data.event.tags', []);

        return array_map(
            fn (array $tag): string => (string) ($tag['key'] ?? ''),
            $tags,
        );
    }
}
