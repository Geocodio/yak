<?php

namespace App\Channels\Sentry;

use App\Http\Concerns\RecordsWebhookTelemetry;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\ObservationRecorder;
use App\Services\RepoDetector;
use App\Services\ResponsiblePersonResolver;
use App\Services\TaskLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    use RecordsWebhookTelemetry;
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.sentry.webhook_secret'),
            signatureHeader: 'Sentry-Hook-Signature',
            prefix: '',
        );

        $action = (string) $request->input('action', '');

        return $this->recordWebhook(
            'sentry',
            $action !== '' ? "issue_alert.{$action}" : 'issue_alert',
            fn (): JsonResponse => $this->route($request),
            ['project' => InputDriver::projectSlug($request)],
        );
    }

    private function route(Request $request): JsonResponse
    {
        // Only handle triggered issue alert events
        if ($request->input('action') !== 'triggered') {
            return response()->json(['ok' => true, 'skipped' => 'not a triggered alert']);
        }

        $tags = $this->extractTagKeys($request);

        // Optional per-event opt-in, off unless configured. See
        // `yak.channels.sentry.required_tag`.
        $requiredTag = config('yak.channels.sentry.required_tag');

        if (is_string($requiredTag) && ! in_array($requiredTag, $tags, true)) {
            return $this->rejected($request, "missing_tag:{$requiredTag}", $tags);
        }

        $rejection = Filter::rejectionReason(
            culprit: (string) $request->input('data.event.culprit', ''),
            title: (string) $request->input('data.event.title', ''),
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
            ObservationRecorder::declined(
                source: 'sentry',
                kind: 'sentry.duplicate',
                summary: "Sentry issue \"{$description->title}\" fired again, but task #{$existingTask->id} already covers it.",
                repo: $resolvedSlug,
                subject: $description->externalId,
                referenceUrl: $description->metadata['sentry_issue_url'] ?: null,
                task: $existingTask,
            );

            return response()->json(['error' => 'duplicate'], 409);
        }

        $task = YakTask::create([
            'source' => 'sentry',
            'repo' => $resolvedSlug,
            'external_id' => $description->externalId,
            'external_url' => $description->metadata['sentry_issue_url'] ?: null,
            'description' => $description->body,
            'context' => json_encode($description->metadata),
            'mode' => 'fix',
            'responsible_name' => app(ResponsiblePersonResolver::class)->resolve(null, null, $resolvedSlug),
            'responsible_user_id' => app(ResponsiblePersonResolver::class)->resolveUser(null, null, $resolvedSlug)?->id,
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'sentry', 'repo' => $resolvedSlug]);
        app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);

        ObservationRecorder::acted(
            source: 'sentry',
            kind: 'sentry.task_created',
            summary: "Sentry issue \"{$description->title}\" fired; opened task #{$task->id}.",
            repo: $resolvedSlug,
            subject: $description->externalId,
            referenceUrl: $task->external_url,
            task: $task,
        );

        return response()->json(['ok' => true, 'task_id' => $task->id], 201);
    }

    /**
     * Record why an issue was dropped and answer 200 so Sentry doesn't retry.
     *
     * Every rejection here is a deliberate no-op, but a silent one is
     * indistinguishable from a webhook that never arrived — which is how the
     * Sentry channel sat idle unnoticed. The log line and the observation are
     * the trace.
     *
     * @param  list<string>  $tags
     */
    private function rejected(Request $request, string $reason, array $tags): JsonResponse
    {
        $project = InputDriver::projectSlug($request);
        $issueId = (string) $request->input('data.event.issue_id', '');

        Log::channel('yak')->debug('Sentry issue filtered', [
            'reason' => $reason,
            'issue_id' => $issueId,
            'project' => $project,
            'tag_keys' => $tags,
        ]);

        $title = Str::limit((string) $request->input('data.event.title', ''), 100);
        [$kind, $why] = match (true) {
            $reason === 'csp_violation' => ['sentry.csp_violation', 'is a Content Security Policy report, which has no code fix'],
            $reason === 'transient_error' => ['sentry.transient_error', 'is a transient infrastructure error'],
            $reason === 'unknown_project' => ['sentry.unknown_project', "comes from Sentry project \"{$project}\", which is not linked to an active repository"],
            default => ['sentry.missing_tag', 'lacks the required opt-in tag'],
        };

        ObservationRecorder::declined(
            source: 'sentry',
            kind: $kind,
            summary: "Sentry issue \"{$title}\" {$why}, so Yak took no action.",
            repo: Repository::where('sentry_project', $project)->where('is_active', true)->value('slug'),
            subject: $issueId !== '' ? $issueId : null,
            referenceUrl: preg_replace('#events/[^/]+/?$#', '', (string) $request->input('data.event.web_url', '')) ?: null,
        );

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
