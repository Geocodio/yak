<?php

namespace App\Channels\Sentry;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\RunYakJob;
use App\Models\YakTask;
use App\Services\RepoDetector;
use App\Services\TaskLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.sentry.webhook_secret'),
            signatureHeader: 'Sentry-Hook-Signature',
            prefix: '',
        );

        // Only handle triggered issue alert events
        if ($request->input('action') !== 'triggered') {
            return response()->json(['ok' => true]);
        }

        /** @var array{id?: string|int, title?: string, culprit?: string, count?: string|int, firstSeen?: string, userCount?: int, seerActionability?: string, project?: array{slug?: string}} $issue */
        $issue = $request->input('data.issue', []);

        $tags = $this->extractTagKeys($request);

        // Optional per-event opt-in, off unless configured. See
        // `yak.channels.sentry.required_tag`.
        $requiredTag = config('yak.channels.sentry.required_tag');

        if (is_string($requiredTag) && ! in_array($requiredTag, $tags, true)) {
            return $this->rejected($request, "missing_tag:{$requiredTag}", $tags);
        }

        $hasPriorityTag = in_array('yak-priority', $tags, true);

        // Apply filtering rules
        $rejection = Filter::rejectionReason(
            culprit: (string) ($issue['culprit'] ?? ''),
            title: (string) ($issue['title'] ?? ''),
            actionability: (string) ($issue['seerActionability'] ?? 'not_actionable'),
            eventCount: (int) ($issue['count'] ?? 0),
            hasPriorityTag: $hasPriorityTag,
            minActionability: (string) config('yak.channels.sentry.min_actionability', 'medium'),
            minEvents: (int) config('yak.channels.sentry.min_events', 5),
        );

        if ($rejection !== null) {
            return $this->rejected($request, $rejection, $tags);
        }

        // Parse the payload into a task description
        $driver = new InputDriver;
        $description = $driver->parse($request);

        $detector = new RepoDetector;
        $detection = $detector->detect($description);

        // Repo must be resolved (sentry_project mapped to an active repository)
        if (! $detection->resolved) {
            return $this->rejected($request, 'unknown_project', $tags);
        }

        $resolvedSlug = $detection->firstRepository()->slug;

        // Deduplication: same external_id + repo = conflict
        $existingTask = YakTask::where('external_id', $description->externalId)
            ->where('repo', $resolvedSlug)
            ->first();

        if ($existingTask !== null) {
            return response()->json(['error' => 'duplicate'], 409);
        }

        $task = YakTask::create([
            'source' => 'sentry',
            'repo' => $resolvedSlug,
            'external_id' => $description->externalId,
            'description' => $description->body,
            'mode' => 'fix',
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'sentry', 'repo' => $resolvedSlug]);
        RunYakJob::dispatch($task);

        return response()->json(['ok' => true, 'task_id' => $task->id], 201);
    }

    /**
     * Record why an issue was dropped and answer 200 so Sentry doesn't retry.
     *
     * Every rejection here is a deliberate no-op, but a silent one is
     * indistinguishable from a webhook that never arrived — which is how the
     * Sentry channel sat idle unnoticed. The log line is the only trace.
     *
     * @param  list<string>  $tags
     */
    private function rejected(Request $request, string $reason, array $tags): JsonResponse
    {
        Log::channel('yak')->debug('Sentry issue filtered', [
            'reason' => $reason,
            'issue_id' => $request->input('data.issue.id'),
            'project' => $request->input('data.issue.project.slug'),
            'tag_keys' => $tags,
        ]);

        return response()->json(['ok' => true, 'filtered' => $reason]);
    }

    /**
     * Extract tag key names from the event's tags array.
     *
     * Sentry serializes event tags two ways depending on the payload: a list
     * of `{key, value}` objects, or a list of `[key, value]` pairs. Read both
     * so an opt-in tag can't be missed on a shape technicality.
     *
     * @return list<string>
     */
    private function extractTagKeys(Request $request): array
    {
        // Deliberately typed loose: this is unvalidated webhook JSON, and the
        // guards below are the only thing standing between a malformed
        // payload and a fatal.
        /** @var mixed $tags */
        $tags = $request->input('data.event.tags', []);

        if (! is_array($tags)) {
            return [];
        }

        $keys = [];

        foreach ($tags as $tag) {
            if (! is_array($tag)) {
                continue;
            }

            $key = $tag['key'] ?? ($tag[0] ?? null);

            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
