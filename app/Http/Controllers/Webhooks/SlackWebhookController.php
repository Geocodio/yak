<?php

namespace App\Http\Controllers\Webhooks;

use App\Drivers\SlackInputDriver;
use App\Drivers\SlackNotificationDriver;
use App\Enums\NotificationType;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
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

        $repository = $description->repository !== null
            ? Repository::where('slug', $description->repository)->first()
            : Repository::where('is_default', true)->first();

        $task = YakTask::create([
            'source' => 'slack',
            'repo' => $repository !== null ? $repository->slug : ($description->repository ?? 'unknown'),
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
