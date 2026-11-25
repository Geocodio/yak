<?php

namespace App\Http\Resources;

use App\Enums\TaskRunKind;
use App\Enums\ThreadSource;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\LegacyConversation;
use App\Services\Threads\ThreadOrigin;
use App\Support\AttachmentReferences;
use App\Support\ChannelCard;
use App\Support\Markdown;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The person's messages in a thread, in send order, with whether the model has
 * taken each one in. State follows `thread_turn_messages`.
 *
 * @phpstan-type ReviewRow array{reviewer: ?string, avatarUrl: ?string, state: string, summaryHtml: string, url: ?string, comments: list<array{author: ?string, bodyHtml: string, file: ?string, line: ?int, diff: list<string>, url: ?string}>}
 *
 * @phpstan-import-type Card from ChannelCard
 *
 * @phpstan-type MessageRow array{review?: ?ReviewRow, channelCard?: ?Card, id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, via: ?array{key: string, label: string, reference: ?string, url: ?string}, isReply: bool, isRetry: bool, retryNumber: ?int, model: ?string, effort: ?string, state: 'pending'|'read'|'cancelled', canEditText: bool, intent: ?string, sendKey: ?string, mode: 'steer'|'queue'|null, sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}
 */
final class ThreadMessagesData
{
    /** How many diff lines a review comment shows above it, as GitHub does. */
    private const int DIFF_CONTEXT_LINES = 4;

    /**
     * @return list<MessageRow>
     */
    public static function for(Thread $thread, ?User $viewer = null): array
    {
        if ($thread->is_legacy) {
            return LegacyConversation::for($thread)['messages'];
        }

        $rows = ThreadTurnMessage::query()
            ->whereHas('taskRun', fn ($runs) => $runs->where('thread_id', $thread->id))
            ->with(['taskRun.task.attachments', 'taskRun.task.author', 'taskRun.task.startedBy', 'taskRun.driver', 'pendingSteeringMessage.attachments', 'attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Files sent before messages carried their own belong to the task's first run.
        $firstRunIds = TaskRun::query()
            ->whereIn('yak_task_id', $rows->pluck('taskRun.yak_task_id')->filter()->unique())
            ->groupBy('yak_task_id')
            ->selectRaw('min(id) as id')
            ->pluck('id');

        $readAt = self::readSequences($thread);
        $messages = [];

        foreach ($rows as $row) {
            $run = $row->taskRun;

            if ($run === null) {
                continue;
            }

            $steer = $row->kind === TurnMessageKind::Steer ? $row->pendingSteeringMessage : null;

            // A steer keeps its own text, so it still shows once its pending row is drained.
            if ($row->kind === TurnMessageKind::Steer && $steer === null && blank($row->text)) {
                continue;
            }

            // A turn started from queued messages alone carries its prompt row only to track the turn.
            if ($row->kind === TurnMessageKind::Prompt && $row->text === '' && $row->intent === null) {
                continue;
            }

            $channelSource = $steer->source ?? $row->source;
            $authorName = $row->author_name ?? $steer?->author_name;
            $isChannelSteer = $row->kind === TurnMessageKind::Steer && ($steer !== null || $row->author_name !== null || $row->source !== null);

            // A message shows the files it carried; the task's unlinked request files belong to its first run.
            $files = $steer !== null
                ? $steer->attachments
                : $row->attachments->concat($firstRunIds->contains($run->id)
                    ? ($run->task?->attachments->where('context', TaskAttachment::CONTEXT_REQUEST)->whereNull('thread_turn_message_id') ?? [])
                    : [])->unique('id')->values();
            $attachments = array_values($files->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all());
            $isRetry = $run->kind === TaskRunKind::Retry;

            if ($isRetry) {
                $files = collect();
                $attachments = [];
            }

            $text = $isRetry ? '' : ($steer !== null ? $steer->text : ($row->text ?? (string) $run->task?->description));
            $references = array_values(array_filter(array_column($attachments, 'reference')));

            $author = $isChannelSteer
                ? self::channelReply($channelSource) + self::author(null, $authorName)
                : (($run->task !== null && $firstRunIds->contains($run->id))
                    ? self::author($run->task, $run->task->author?->name, $run->task->author_name, $run->driver?->name, $run->task->startedBy?->name)
                    : self::author(null, $run->driver?->name));

            $reviewFeedback = $isRetry ? null : ($steer !== null ? $steer->review_feedback : ($row->review_feedback ?? ($firstRunIds->contains($run->id) ? $run->task?->review_feedback : null)));

            // A review links its source to the review on the pull request.
            if ($reviewFeedback !== null) {
                $author['via'] = [
                    'key' => ThreadSource::Github->value,
                    'label' => ThreadSource::Github->label(),
                    'reference' => $run->task?->pr_number !== null ? '#' . $run->task->pr_number : null,
                    'url' => is_string($reviewFeedback['url'] ?? null) ? $reviewFeedback['url'] : null,
                ];
                $author['isReply'] = true;
            }

            $channelCard = $isRetry ? null : ($steer !== null ? $steer->channel_card : ($row->channel_card ?? ($firstRunIds->contains($run->id) ? $run->task?->channel_card : null)));

            // A reply links its source to the message it came from.
            if ($channelCard !== null && $author['isReply'] && $author['via'] !== null && $author['via']['url'] === null) {
                $author['via']['url'] = is_string($channelCard['url'] ?? null) ? $channelCard['url'] : null;
            }

            $messages[] = [
                'id' => 'turn-message-' . $row->id,
                'channelCard' => $channelCard === null ? null : self::channelCard($channelCard),
                'review' => $reviewFeedback === null ? null : self::review($reviewFeedback),
                'uuid' => $row->uuid,
                // The assembled prompt on task_runs is never shown; it carries Yak's own notes.
                'text' => $text,
                ...$author,
                'isRetry' => $isRetry,
                'retryNumber' => $isRetry ? TaskRun::query()->where('yak_task_id', $run->yak_task_id)->where('kind', TaskRunKind::Retry)->where('id', '<=', $run->id)->count() : null,
                'model' => $steer !== null ? $steer->model : $run->model,
                'effort' => $steer !== null ? $steer->effort : $run->effort,
                'state' => match ($row->state) {
                    TurnMessageState::Sent, TurnMessageState::Queued => 'pending',
                    TurnMessageState::Started, TurnMessageState::Completed => 'read',
                    TurnMessageState::Cancelled => 'cancelled',
                },
                'canEditText' => false,
                'intent' => $row->intent,
                'sendKey' => $row->send_key,
                'mode' => null,
                // A steer row is made when Yak hands the message over; the person sent it when its pending row was made.
                'sentAt' => ($steer->created_at ?? $row->created_at)?->toIso8601String() ?? '',
                'readAfterSequence' => $readAt['messages'][$row->uuid] ?? $readAt['turns'][$run->id] ?? null,
                'bodyHtml' => self::markMentions(AttachmentReferences::linkInHtml(Markdown::toHtml($text), $references), $channelCard),
                'attachments' => $attachments,
                'attachmentIds' => array_values($files->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            ];
        }

        $root = $thread->tasks()->oldest()->first()?->rootTask();

        // The first turn writes its message row later, so until a run exists the starting request is the pending message.
        if ($rows->isEmpty() && $root !== null && filled($root->description) && ! $thread->runs()->exists()) {
            $continuesLegacy = $thread->continued_from_thread_id !== null && Thread::query()->withoutGlobalScopes()->whereKey($thread->continued_from_thread_id)->where('is_legacy', true)->exists();
            $messages[] = self::initialMessage($continuesLegacy ? ($thread->headTask() ?? $root) : $root);
        }

        $delivered = array_filter(array_column($messages, 'uuid'));
        $undelivered = $root === null ? collect() : PendingSteeringMessage::query()
            ->where('root_task_id', $root->id)
            ->where('source', 'thread')
            ->with('attachments')
            ->orderBy('id')
            ->get()
            ->reject(fn (PendingSteeringMessage $queued): bool => in_array($queued->uuid, $delivered, true));

        // A message typed while the thread waits is saved but not handed to a turn yet.
        foreach ($undelivered as $queued) {
            $attachments = array_values($queued->attachments->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all());

            $messages[] = [
                'id' => 'queued-message-' . $queued->id,
                'uuid' => $queued->uuid,
                'text' => $queued->text,
                ...self::author(null, $queued->author_name),
                'isRetry' => false,
                'retryNumber' => null,
                'model' => $queued->model,
                'effort' => $queued->effort,
                'state' => 'pending',
                'canEditText' => $queued->canEditTextBy($viewer),
                'intent' => null,
                'sendKey' => $queued->send_key,
                'mode' => $queued->mode->value,
                'sentAt' => $queued->created_at?->toIso8601String() ?? '',
                'readAfterSequence' => null,
                'bodyHtml' => AttachmentReferences::linkInHtml(Markdown::toHtml($queued->text), array_values(array_filter(array_column($attachments, 'reference')))),
                'attachments' => $attachments,
                'attachmentIds' => array_values($queued->attachments->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            ];
        }

        return $messages;
    }

    /**
     * @return array{id: string, channelCard: ?Card, uuid: ?string, text: string, authorName: ?string, authorInitials: string, via: ?array{key: string, label: string, reference: ?string, url: ?string}, isReply: bool, isRetry: bool, retryNumber: ?int, model: ?string, effort: ?string, state: 'pending'|'read'|'cancelled', canEditText: bool, intent: ?string, sendKey: ?string, mode: 'steer'|'queue'|null, sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}
     */
    private static function initialMessage(YakTask $task): array
    {
        $files = $task->attachments()->where('context', TaskAttachment::CONTEXT_REQUEST)->whereNull('thread_turn_message_id')->get();
        $attachments = array_values($files->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all());

        return [
            'id' => 'initial-message',
            'channelCard' => $task->channel_card === null ? null : self::channelCard($task->channel_card),
            'uuid' => null,
            'text' => (string) $task->description,
            ...self::author($task, $task->author?->name, $task->author_name, $task->startedBy?->name),
            'isRetry' => false,
            'retryNumber' => null,
            'model' => null,
            'effort' => null,
            'state' => 'pending',
            'canEditText' => false,
            'intent' => null,
            'sendKey' => null,
            'mode' => null,
            'sentAt' => $task->created_at?->toIso8601String() ?? '',
            'readAfterSequence' => null,
            'bodyHtml' => AttachmentReferences::linkInHtml(Markdown::toHtml((string) $task->description), array_values(array_filter(array_column($attachments, 'reference')))),
            'attachments' => $attachments,
            'attachmentIds' => array_values($files->pluck('id')->map(fn ($id): int => (int) $id)->all()),
        ];
    }

    /**
     * A GitHub review shaped the way the thread shows it: Markdown rendered, and each diff cut to the lines
     * the comment sits on, as GitHub shows them.
     *
     * @param  array<string, mixed>  $feedback
     * @return ReviewRow
     */
    private static function review(array $feedback): array
    {
        $reviewer = is_string($feedback['reviewer'] ?? null) ? $feedback['reviewer'] : null;

        return [
            'reviewer' => $reviewer,
            'avatarUrl' => $reviewer === null ? null : 'https://github.com/' . rawurlencode($reviewer) . '.png?size=48',
            'state' => (string) ($feedback['state'] ?? ''),
            'summaryHtml' => Markdown::toHtml((string) ($feedback['summary'] ?? '')),
            'url' => is_string($feedback['url'] ?? null) ? $feedback['url'] : null,
            'comments' => array_values(array_map(fn (array $comment): array => [
                'author' => is_string($comment['author'] ?? null) ? $comment['author'] : null,
                'bodyHtml' => Markdown::toHtml((string) ($comment['body'] ?? '')),
                'file' => is_string($comment['file'] ?? null) ? $comment['file'] : null,
                'line' => is_int($comment['line'] ?? null) ? $comment['line'] : null,
                'diff' => array_slice(array_values(array_filter(
                    explode("\n", (string) ($comment['diff_hunk'] ?? '')),
                    fn (string $line): bool => $line !== '' && ! str_starts_with($line, '@@'),
                )), -self::DIFF_CONTEXT_LINES),
                'url' => is_string($comment['url'] ?? null) ? $comment['url'] : null,
            ], array_filter((array) ($feedback['comments'] ?? []), 'is_array'))),
        ];
    }

    /**
     * A stored channel card in the shape the thread reads, with every field checked.
     *
     * @param  array<string, mixed>  $card
     * @return Card
     */
    private static function channelCard(array $card): array
    {
        $text = fn (string $key): ?string => is_string($card[$key] ?? null) && $card[$key] !== '' ? $card[$key] : null;
        $channel = $card['channel'] ?? null;

        return [
            'channel' => in_array($channel, ['slack', 'linear', 'email'], true) ? $channel : 'email',
            'url' => $text('url'),
            'reference' => $text('reference'),
            'title' => $text('title'),
            'avatarUrl' => $text('avatarUrl'),
            'mentions' => array_values(array_filter((array) ($card['mentions'] ?? []), 'is_string')),
        ];
    }

    /**
     * Wraps each name a Slack message mentions in a `<mark data-mention>`, so the thread can highlight it.
     *
     * @param  array<string, mixed>|null  $card
     */
    private static function markMentions(string $html, ?array $card): string
    {
        foreach ((array) ($card['mentions'] ?? []) as $name) {
            if (is_string($name) && $name !== '') {
                $mention = '@' . e($name);
                $html = str_replace($mention, "<mark data-mention>{$mention}</mark>", $html);
            }
        }

        return $html;
    }

    /**
     * A message that came from Slack, Linear or a GitHub review keeps its source once it is steered into a turn.
     *
     * @return array{via?: array{key: string, label: string, reference: null, url: null}, isReply?: true}
     */
    private static function channelReply(?string $pendingSource): array
    {
        $source = match ($pendingSource) {
            'slack' => ThreadSource::Slack,
            'linear' => ThreadSource::Linear,
            'github_review' => ThreadSource::Github,
            default => null,
        };

        return $source === null ? [] : ['via' => ['key' => $source->value, 'label' => $source->label(), 'reference' => null, 'url' => null], 'isReply' => true];
    }

    /**
     * The event sequence at which Claude took in each message, so the conversation places a message right before the
     * reply it started instead of with earlier history: a message's own "started" state, else its turn starting.
     *
     * @return array{messages: array<string, int>, turns: array<int, int>}
     */
    private static function readSequences(Thread $thread): array
    {
        $sequences = ['messages' => [], 'turns' => []];

        $events = ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->whereIn('type', ['message.state', 'turn'])
            ->orderBy('sequence')
            ->get(['type', 'sequence', 'payload']);

        foreach ($events as $event) {
            $payload = $event->payload;

            if ($event->type === 'message.state' && ($payload['state'] ?? null) === 'started' && is_string($payload['uuid'] ?? null)) {
                $sequences['messages'][$payload['uuid']] ??= $event->sequence;
            }

            if ($event->type === 'turn' && ($payload['state'] ?? null) === 'running' && is_int($payload['turnId'] ?? null)) {
                $sequences['turns'][$payload['turnId']] ??= $event->sequence;
            }
        }

        return $sequences;
    }

    /**
     * Who wrote a message and from where. The name is the first one known, else "Yak", so a message never shows "?".
     * A task from a channel carries the channel it came from; a reply is a follow-up that came from one.
     *
     * @return array{authorName: string, authorInitials: string, via: ?array{key: string, label: string, reference: ?string, url: ?string}, isReply: bool}
     */
    public static function author(?YakTask $task, ?string ...$names): array
    {
        $name = Arr::first($names, fn (?string $candidate): bool => filled($candidate)) ?? 'Yak';
        $origin = $task === null ? null : app(ThreadOrigin::class)->forRequest($task);
        $source = $origin['source'] ?? ThreadSource::Dashboard;

        return [
            'authorName' => $name,
            'authorInitials' => self::initials($name),
            'via' => $source === ThreadSource::Dashboard ? null : [
                'key' => $source->value,
                'label' => $source->label(),
                'reference' => $origin['source_reference'] ?? null,
                'url' => $origin['source_url'] ?? null,
            ],
            'isReply' => $task?->parent_task_id !== null && in_array($source, [ThreadSource::Slack, ThreadSource::Linear, ThreadSource::Sentry, ThreadSource::Github, ThreadSource::Email], true),
        ];
    }

    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        return Str::upper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    }
}
