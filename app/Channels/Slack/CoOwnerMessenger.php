<?php

namespace App\Channels\Slack;

use App\Enums\CoOwnerRequestStatus;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\EarlyWarningState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts and updates the Slack messages of a held change: the thread reply to
 * the requester and one direct message per person asked. Tasks that did not
 * come from Slack get the thread text as a Progress notification instead.
 */
class CoOwnerMessenger
{
    public function held(CoOwnerRequest $request): void
    {
        if ($request->status !== CoOwnerRequestStatus::Pending) {
            return;
        }

        if ($request->thread_message_ts === null) {
            $paragraphs = CoOwnerBlocks::threadHeldParagraphs($request, shouldEscape: false);
            $ts = $this->postToThread($request, CoOwnerBlocks::threadHeld($request), implode("\n\n", $paragraphs));

            if ($ts !== null) {
                $request->update(['thread_message_ts' => $ts]);
            }
        }

        $this->sendDirectMessages($request, fn (User $user): array => CoOwnerBlocks::directMessage($request, $user));
    }

    /**
     * Tells the requester a co-owner will probably be needed. Slack tasks get
     * a thread reply with controls, the dashboard shows the notice on the task
     * page, and other sources get a Progress notification.
     *
     * @param  array{areas: list<array{name: string, risk: string}>, repoName: string, fallbackName: ?string}  $warning
     */
    public function earlyWarning(YakTask $task, array $warning): void
    {
        if ($task->source === 'dashboard') {
            return;
        }

        if ($task->source !== 'slack') {
            SendNotificationJob::dispatch(
                $task,
                NotificationType::Progress,
                CoOwnerBlocks::earlyWarningLead($warning, shouldEscape: false) . ' You can tag someone on the task page.',
                personalize: false,
            );

            return;
        }

        if (! $task->slack_channel || ! $task->slack_thread_ts) {
            return;
        }

        $blocks = CoOwnerBlocks::earlyWarning($task, $warning);
        $response = $this->post('chat.postMessage', [
            'channel' => $task->slack_channel,
            'thread_ts' => $task->slack_thread_ts,
            'text' => CoOwnerBlocks::fallbackText($blocks),
            'blocks' => $blocks,
        ], $task);

        if (isset($response['ts'], $response['channel'])) {
            app(EarlyWarningState::class)->setSlackMessage($task, (string) $response['channel'], (string) $response['ts']);
        }
    }

    /**
     * Rewrites the early warning message to match the stored state: the
     * picked co-owner or the decline, with no controls. Uses the message
     * the click came from when given, else the one stored on the root.
     */
    public function updateEarlyWarning(YakTask $task, ?string $channel = null, ?string $timestamp = null): void
    {
        $root = $task->rootTask();
        $stored = $root->early_warning;
        $channel ??= $stored['slackChannel'] ?? null;
        $timestamp ??= $stored['slackTs'] ?? null;

        if ($stored === null || $channel === null || $timestamp === null) {
            return;
        }

        $repository = $root->repository;
        $fallbackName = $repository?->defaultResponsibleUser?->name;
        $warning = [
            'areas' => $stored['areas'],
            'repoName' => (string) $repository?->github_full_name,
            'fallbackName' => $fallbackName === null ? null : explode(' ', trim($fallbackName))[0],
        ];

        $blocks = match ($stored['state']) {
            'tagged' => CoOwnerBlocks::earlyWarningTagged($warning, (string) User::find($root->tagged_co_owner_user_id)?->name),
            'declined' => CoOwnerBlocks::earlyWarningDeclined($warning),
            default => CoOwnerBlocks::earlyWarning($root, $warning),
        };

        $this->post('chat.update', [
            'channel' => $channel,
            'ts' => $timestamp,
            'text' => CoOwnerBlocks::fallbackText($blocks),
            'blocks' => $blocks,
        ], $task);
    }

    public function resolved(CoOwnerRequest $request): void
    {
        foreach ($request->direct_messages as $message) {
            $recipient = User::find($message['userId']);

            if ($recipient === null) {
                continue;
            }

            $blocks = CoOwnerBlocks::resolved($request, $recipient);
            $this->post('chat.update', [
                'channel' => $message['channel'],
                'ts' => $message['ts'],
                'text' => CoOwnerBlocks::fallbackText($blocks),
                'blocks' => $blocks,
            ], $request, $recipient);
        }

        $note = CoOwnerBlocks::resolvedNoteText($request, shouldEscape: false);

        if ($note !== null) {
            $this->postToThread($request, CoOwnerBlocks::threadResolved($request), $note);
        }
    }

    public function remind(CoOwnerRequest $request): void
    {
        if ($request->status !== CoOwnerRequestStatus::Pending) {
            return;
        }

        $this->sendDirectMessages($request, fn (User $user): array => CoOwnerBlocks::reminder($request), isReminder: true);
        $this->postToThread($request, CoOwnerBlocks::threadReminder($request), CoOwnerBlocks::reminderNoteText($request, shouldEscape: false));
    }

    /**
     * Answers a button click through its `response_url`, visible only to the person who clicked.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function ephemeral(string $responseUrl, string $text, array $blocks = []): void
    {
        try {
            Http::post($responseUrl, array_filter([
                'response_type' => 'ephemeral',
                'replace_original' => false,
                'text' => $text,
                'blocks' => $blocks,
            ], fn (mixed $value): bool => $value !== []));
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Co-owner ephemeral reply was not delivered', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  callable(User): list<array<string, mixed>>  $blocksFor
     */
    private function sendDirectMessages(CoOwnerRequest $request, callable $blocksFor, bool $isReminder = false): void
    {
        $sent = collect($request->direct_messages);
        $users = $request->askedUsers()->keyBy('id');

        foreach ($request->asked_user_ids as $userId) {
            $user = $users->get($userId);

            if ($user === null || (! $isReminder && $sent->contains('userId', $userId))) {
                continue;
            }

            $slackUserId = app(UserLookup::class)->slackUserIdFor($user);

            if ($slackUserId === null) {
                continue;
            }

            $blocks = $blocksFor($user);
            $response = $this->post('chat.postMessage', [
                'channel' => $slackUserId,
                'text' => CoOwnerBlocks::fallbackText($blocks),
                'blocks' => $blocks,
            ], $request, $user);

            if (! $isReminder && $response !== null) {
                $sent->push(['userId' => $user->id, 'channel' => (string) $response['channel'], 'ts' => (string) $response['ts']]);
                $request->update(['direct_messages' => $sent->values()->all()]);
            }
        }
    }

    /**
     * Posts into the task's Slack thread, or sends the text as a notification
     * for a task that did not come from Slack. Returns the posted message's ts.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function postToThread(CoOwnerRequest $request, array $blocks, string $text): ?string
    {
        $task = $request->task;

        if ($task->source !== 'slack') {
            SendNotificationJob::dispatch($task, NotificationType::Progress, $text, personalize: false);

            return null;
        }

        if (! $task->slack_channel || ! $task->slack_thread_ts) {
            return null;
        }

        $response = $this->post('chat.postMessage', [
            'channel' => $task->slack_channel,
            'thread_ts' => $task->slack_thread_ts,
            'text' => CoOwnerBlocks::fallbackText($blocks),
            'blocks' => $blocks,
        ], $request);

        return isset($response['ts']) ? (string) $response['ts'] : null;
    }

    /**
     * One Slack API call. A failure is logged and returns null, so one bad
     * message never stops the rest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function post(string $method, array $payload, CoOwnerRequest|YakTask $subject, ?User $recipient = null): ?array
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)->post("https://slack.com/api/{$method}", $payload);

            if ($response->json('ok') === true) {
                return $response->json();
            }

            $error = $response->json('error');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        Log::channel('yak')->warning('Co-owner Slack message was not delivered', [
            ($subject instanceof YakTask ? 'task_id' : 'co_owner_request_id') => $subject->id,
            'method' => $method,
            'user_id' => $recipient?->id,
            'error' => $error,
        ]);

        return null;
    }
}
