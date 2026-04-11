<?php

namespace App\Http\Controllers\Webhooks;

use App\Drivers\SlackInputDriver;
use App\Drivers\SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunYakJob;
use App\Models\YakTask;
use App\Services\RepoDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifySlackSignature($request);

        // URL verification challenge
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        /** @var array{type?: string, bot_id?: string, subtype?: string, channel?: string, thread_ts?: string, text?: string} $event */
        $event = $request->input('event', []);

        // Ignore bot messages to prevent loops
        if (isset($event['bot_id']) || ($event['subtype'] ?? null) === 'bot_message') {
            return response()->json(['ok' => true]);
        }

        return match ($event['type'] ?? null) {
            'app_mention' => $this->handleMention($request),
            'message' => $this->handleThreadReply($event),
            default => response()->json(['ok' => true]),
        };
    }

    /**
     * Verify Slack's signature format: v0=hmac(secret, "v0:{timestamp}:{body}").
     */
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

    /**
     * Handle an @yak app_mention event — create a task and dispatch RunYakJob.
     */
    private function handleMention(Request $request): JsonResponse
    {
        $driver = new SlackInputDriver;
        $description = $driver->parse($request);

        $detector = new RepoDetector;
        $detection = $detector->detect($description);

        // Multi-repo: create one task per repo
        if ($detection->isMultiRepo()) {
            foreach ($detection->repositories as $repo) {
                $task = YakTask::create([
                    'source' => 'slack',
                    'repo' => $repo->slug,
                    'external_id' => $description->externalId.'-'.$repo->slug,
                    'description' => $description->body,
                    'mode' => $description->metadata['mode'] ?? 'fix',
                    'slack_channel' => $description->metadata['slack_channel'],
                    'slack_thread_ts' => $description->metadata['slack_thread_ts'],
                ]);

                RunYakJob::dispatch($task);
            }

            $notification = new SlackNotificationDriver;
            $repoList = $detection->repositories->pluck('slug')->implode(', ');
            $dummyTask = YakTask::where('source', 'slack')
                ->where('slack_channel', $description->metadata['slack_channel'])
                ->where('slack_thread_ts', $description->metadata['slack_thread_ts'])
                ->first();

            if ($dummyTask !== null) {
                $notification->send($dummyTask, NotificationType::Acknowledgment, "I'm on it — working across: {$repoList}");
            }

            return response()->json(['ok' => true]);
        }

        // Low-confidence: needs clarification
        if ($detection->needsClarification) {
            $repoOptions = $detection->options->pluck('slug')->values()->all();

            $task = YakTask::create([
                'source' => 'slack',
                'repo' => 'unknown',
                'external_id' => $description->externalId,
                'description' => $description->body,
                'mode' => $description->metadata['mode'] ?? 'fix',
                'status' => 'awaiting_clarification',
                'slack_channel' => $description->metadata['slack_channel'],
                'slack_thread_ts' => $description->metadata['slack_thread_ts'],
                'clarification_options' => $repoOptions,
                'clarification_expires_at' => now()->addDays((int) config('yak.clarification_ttl_days', 3)),
            ]);

            $notification = new SlackNotificationDriver;
            $optionList = implode(', ', $repoOptions);
            $notification->send($task, NotificationType::Acknowledgment, "Which repo should I work in? Options: {$optionList}");

            return response()->json(['ok' => true]);
        }

        // Single resolved repo or unresolved
        $repoSlug = $detection->resolved
            ? $detection->firstRepository()->slug
            : ($description->repository ?? 'unknown');

        $task = YakTask::create([
            'source' => 'slack',
            'repo' => $repoSlug,
            'external_id' => $description->externalId,
            'description' => $description->body,
            'mode' => $description->metadata['mode'] ?? 'fix',
            'slack_channel' => $description->metadata['slack_channel'],
            'slack_thread_ts' => $description->metadata['slack_thread_ts'],
        ]);

        $notification = new SlackNotificationDriver;
        $notification->send($task, NotificationType::Acknowledgment, "I'm on it.");

        RunYakJob::dispatch($task);

        return response()->json(['ok' => true]);
    }

    /**
     * Handle a thread reply — dispatch ClarificationReplyJob if task is awaiting clarification.
     *
     * @param  array{channel?: string, thread_ts?: string, text?: string}  $event
     */
    private function handleThreadReply(array $event): JsonResponse
    {
        $channel = $event['channel'] ?? '';
        $threadTs = $event['thread_ts'] ?? '';

        if ($channel === '' || $threadTs === '') {
            return response()->json(['ok' => true]);
        }

        $task = YakTask::where('slack_channel', $channel)
            ->where('slack_thread_ts', $threadTs)
            ->where('status', 'awaiting_clarification')
            ->first();

        if ($task !== null) {
            ClarificationReplyJob::dispatch($task, $event['text'] ?? '');
        }

        return response()->json(['ok' => true]);
    }
}
