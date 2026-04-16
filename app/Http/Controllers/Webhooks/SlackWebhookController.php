<?php

namespace App\Http\Controllers\Webhooks;

use App\Drivers\SlackInputDriver;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RepoDetector;
use App\Services\TaskLogger;
use App\Support\Docs;
use App\Support\SlackHelpCard;
use App\Support\SlackUserTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

        // Deduplicate Slack event retries using the event_id
        $eventId = $request->input('event_id', '');
        if ($eventId !== '' && ! Cache::add("slack-event:{$eventId}", true, 300)) {
            return response()->json(['ok' => true]);
        }

        return match ($event['type'] ?? null) {
            'app_mention' => $this->handleMention($request),
            'message' => $this->handleThreadReply($event),
            'app_home_opened' => $this->handleAppHomeOpened($event),
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

        // Intercept help queries (`@yak`, `@yak help`, `@yak ?`) before
        // creating a task — post a capabilities card instead and exit.
        if (SlackHelpCard::isHelpQuery($description->body)) {
            $this->postHelpCard(
                channel: (string) ($description->metadata['slack_channel'] ?? ''),
                threadTs: (string) ($description->metadata['slack_thread_ts'] ?? ''),
            );

            return response()->json(['ok' => true]);
        }

        $detector = new RepoDetector;
        $detection = $detector->detect($description);

        // Multi-repo: create one task per repo
        if ($detection->isMultiRepo()) {
            foreach ($detection->repositories as $repo) {
                $task = YakTask::create([
                    'source' => 'slack',
                    'repo' => $repo->slug,
                    'external_id' => $description->externalId . '-' . $repo->slug,
                    'description' => $description->body,
                    'mode' => $description->metadata['mode'] ?? 'fix',
                    'slack_channel' => $description->metadata['slack_channel'],
                    'slack_thread_ts' => $description->metadata['slack_thread_ts'],
                    'slack_user_id' => $description->metadata['slack_user_id'] ?? null,
                    'slack_message_ts' => $description->metadata['slack_message_ts'] ?? null,
                ]);

                TaskLogger::info($task, 'Task created', ['source' => 'slack', 'repo' => $repo->slug]);
                $this->dispatchAgentJob($task);
            }

            $repoList = $detection->repositories->pluck('slug')->implode(', ');
            $dummyTask = YakTask::where('source', 'slack')
                ->where('slack_channel', $description->metadata['slack_channel'])
                ->where('slack_thread_ts', $description->metadata['slack_thread_ts'])
                ->first();

            if ($dummyTask !== null) {
                SendNotificationJob::dispatch($dummyTask, NotificationType::Acknowledgment, "Working across repos: {$repoList}");
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
                'slack_user_id' => $description->metadata['slack_user_id'] ?? null,
                'slack_message_ts' => $description->metadata['slack_message_ts'] ?? null,
                'clarification_options' => $repoOptions,
                'clarification_expires_at' => now()->addDays((int) config('yak.clarification_ttl_days', 3)),
            ]);

            TaskLogger::info($task, 'Task created — awaiting repo clarification', ['source' => 'slack', 'options' => $repoOptions]);
            $optionList = implode(', ', $repoOptions);
            SendNotificationJob::dispatch($task, NotificationType::Clarification, "Which repo should I work in? Options: {$optionList}");

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
            'slack_user_id' => $description->metadata['slack_user_id'] ?? null,
            'slack_message_ts' => $description->metadata['slack_message_ts'] ?? null,
        ]);

        TaskLogger::info($task, 'Task created', ['source' => 'slack', 'repo' => $repoSlug]);
        SendNotificationJob::dispatch($task, NotificationType::Acknowledgment, "Task: {$task->description}");

        $this->dispatchAgentJob($task);

        return response()->json(['ok' => true]);
    }

    /**
     * Handle `app_home_opened` — fires whenever a user opens Yak's App
     * Home tab. We DM the user a welcome card the first time they do
     * so, then mark them as seen via SlackUserTracker so the in-channel
     * first-timer block doesn't fire on top of it later.
     *
     * @param  array{user?: string, tab?: string}  $event
     */
    private function handleAppHomeOpened(array $event): JsonResponse
    {
        $userId = (string) ($event['user'] ?? '');

        if ($userId === '' || ! SlackUserTracker::markSeen($userId)) {
            return response()->json(['ok' => true]);
        }

        $this->postWelcomeDm($userId);

        return response()->json(['ok' => true]);
    }

    private function postWelcomeDm(string $userId): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return;
        }

        $docsUrl = Docs::url('home');
        $channelsUrl = Docs::url('channels.slack');
        $dashboardUrl = rtrim((string) config('app.url'), '/');

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $userId,
                'text' => "Hi — I'm Yak. I turn Slack messages and Linear issues into reviewable pull requests.",
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "*Hi — I'm Yak.* 🐃 I turn Slack messages and Linear issues into reviewable pull requests.",
                        ],
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "*Try it:* mention me in any channel I'm in.\n"
                                . "• `@yak fix the login bug on staging`\n"
                                . "• `@yak in my-repo: add a unit test for UserService`\n"
                                . '• `@yak research: how does the payments webhook flow work?`',
                        ],
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'text' => ['type' => 'plain_text', 'text' => 'Open dashboard'],
                                'url' => $dashboardUrl,
                                'style' => 'primary',
                            ],
                            [
                                'type' => 'button',
                                'text' => ['type' => 'plain_text', 'text' => 'Slack guide'],
                                'url' => $channelsUrl,
                            ],
                            [
                                'type' => 'button',
                                'text' => ['type' => 'plain_text', 'text' => 'Full docs'],
                                'url' => $docsUrl,
                            ],
                        ],
                    ],
                ],
            ]);
    }

    private function postHelpCard(string $channel, string $threadTs): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || $channel === '' || $threadTs === '') {
            return;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $channel,
                'thread_ts' => $threadTs,
                'text' => SlackHelpCard::fallbackText(),
                'blocks' => SlackHelpCard::blocks(),
            ]);
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
            $replyText = $event['text'] ?? '';
            TaskLogger::info($task, 'Clarification received');

            if ($task->repo === 'unknown' && $task->session_id === null) {
                $this->handleRepoClarification($task, $replyText);
            } else {
                ClarificationReplyJob::dispatch($task, $replyText);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Handle a repo clarification reply — match the reply text to one of the
     * offered repo options, update the task, and dispatch RunYakJob.
     */
    private function handleRepoClarification(YakTask $task, string $replyText): void
    {
        /** @var list<string> $options */
        $options = $task->clarification_options ?? [];
        $replyNormalized = str($replyText)->lower()->trim()->replaceMatches('/[\s\-_]+/', '-');

        // Match against the offered repo slugs (e.g. "acme/api")
        // Normalizes spaces/hyphens/underscores so "geocodio website" matches "geocodio-website"
        $matchedSlug = collect($options)->first(function (string $slug) use ($replyNormalized) {
            $fullNorm = str($slug)->lower()->replaceMatches('/[\s\-_]+/', '-');
            $nameNorm = str($slug)->afterLast('/')->lower()->replaceMatches('/[\s\-_]+/', '-');

            return $replyNormalized->contains($fullNorm) || $replyNormalized->contains($nameNorm);
        });

        if ($matchedSlug === null) {
            TaskLogger::warning($task, 'Could not match repo from reply', ['reply' => $replyText, 'options' => $options]);
            SendNotificationJob::dispatch($task, NotificationType::Clarification, "I didn't recognise that repo. Options: " . implode(', ', $options));

            return;
        }

        $repository = Repository::where('slug', $matchedSlug)->where('is_active', true)->first();

        if ($repository === null) {
            TaskLogger::error($task, "Matched repo slug '{$matchedSlug}' not found or inactive");

            return;
        }

        $task->update([
            'repo' => $repository->slug,
            'status' => TaskStatus::Pending,
            'clarification_options' => null,
            'clarification_expires_at' => null,
        ]);

        TaskLogger::info($task, "Repo resolved to {$repository->slug}");
        $this->dispatchAgentJob($task);
    }
}
