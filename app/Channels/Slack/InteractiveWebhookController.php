<?php

namespace App\Channels\Slack;

use App\Enums\TaskStatus;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ClarificationReplyJob;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use App\Services\TaskLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Handles Slack's Interactivity & Shortcuts webhook — fires when a
 * user clicks a button inside one of Yak's messages. The only
 * interactive element we currently ship is clarification option
 * buttons: clicking one dispatches ClarificationReplyJob with the
 * selected option as the reply text, so the flow reaches Claude
 * identically to a thread-reply answer.
 */
class InteractiveWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifySlackSignature($request);

        // Record receipt as soon as the signature checks out — the
        // health check uses this to detect a missing Interactivity
        // request URL on the Slack side. We record before further
        // payload validation so even a future button type counts.
        InteractivityTracker::recordReceived();

        $payload = json_decode((string) $this->extractPayload($request), true);

        if (! is_array($payload) || ($payload['type'] ?? '') !== 'block_actions') {
            return response()->json(['ok' => true]);
        }

        /** @var array<int, array<string, mixed>> $actions */
        $actions = $payload['actions'] ?? [];
        $action = $actions[0] ?? null;

        if (! is_array($action)) {
            return response()->json(['ok' => true]);
        }

        $actionId = (string) ($action['action_id'] ?? '');
        if (! str_starts_with($actionId, BlockFormatter::CLARIFY_ACTION_ID)) {
            return response()->json(['ok' => true]);
        }

        [$taskId, $optionText] = array_pad(explode('|', (string) ($action['value'] ?? ''), 2), 2, '');

        $task = YakTask::find((int) $taskId);

        if ($task === null || $task->status !== TaskStatus::AwaitingClarification) {
            return response()->json(['ok' => true]);
        }

        TaskLogger::info($task, 'Clarification received via button', ['option' => $optionText]);

        $isRepoChoice = RepoClarificationResolver::awaitingRepoChoice($task);

        if ($isRepoChoice) {
            RepoClarificationResolver::resolve($task, $optionText);
        } else {
            ClarificationReplyJob::dispatch($task, $optionText);
        }

        // Slack hides the click feedback after a moment but doesn't
        // otherwise update the message, so a successful click looks
        // identical to one that went nowhere. Replace the original
        // (button-bearing) message in-place via response_url so the
        // user gets immediate confirmation. We only ack on a confirmed
        // resolution — for repo choices the resolver flips the status
        // synchronously; for in-flight agent clarifications the dispatch
        // itself is the point of no return, so ack there too.
        $resolved = $isRepoChoice
            ? $task->fresh()?->status !== TaskStatus::AwaitingClarification
            : true;

        if ($resolved) {
            $this->ackClick(
                responseUrl: (string) ($payload['response_url'] ?? ''),
                optionText: $optionText,
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Replace the original message containing the clicked button with
     * a short confirmation. response_url is a one-shot Slack-issued
     * webhook bound to the originating message; posting `replace_original`
     * swaps in new text and drops the now-stale buttons.
     */
    private function ackClick(string $responseUrl, string $optionText): void
    {
        if ($responseUrl === '' || $optionText === '') {
            return;
        }

        try {
            Http::timeout(3)->post($responseUrl, [
                'replace_original' => true,
                'text' => "Got it — `{$optionText}` 🐃",
            ]);
        } catch (ConnectionException) {
            // Best-effort feedback. The dispatched job will still run
            // and post its normal lifecycle notifications.
        }
    }

    /**
     * Slack Interactivity posts `payload={url-encoded json}` as
     * form-urlencoded data. In production Laravel parses it into
     * $request->input() automatically, but tests that inject the raw
     * body via call(content:) don't get that wiring, so we fall back
     * to parse_str over the raw content.
     */
    private function extractPayload(Request $request): string
    {
        $payload = (string) $request->input('payload', '');

        if ($payload !== '') {
            return $payload;
        }

        parse_str($request->getContent(), $parsed);

        return (string) ($parsed['payload'] ?? '');
    }

    private function verifySlackSignature(Request $request): void
    {
        $timestamp = $request->header('X-Slack-Request-Timestamp', '');
        $basestring = "v0:{$timestamp}:{$request->getContent()}";

        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.slack.signing_secret'),
            'X-Slack-Signature',
            prefix: 'v0=',
            payload: $basestring,
        );
    }
}
