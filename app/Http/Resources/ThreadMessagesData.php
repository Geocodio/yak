<?php

namespace App\Http\Resources;

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
use App\Support\AttachmentReferences;
use App\Support\Markdown;
use Illuminate\Support\Str;

/**
 * The person's messages in a thread, in send order, with whether the model has
 * taken each one in. State follows `thread_turn_messages`.
 */
final class ThreadMessagesData
{
    /**
     * @return list<array{id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, model: ?string, effort: ?string, state: 'pending'|'read'|'cancelled', canEditText: bool, intent: ?string, sendKey: ?string, mode: 'steer'|'queue'|null, sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}>
     */
    public static function for(Thread $thread, ?User $viewer = null): array
    {
        if ($thread->is_legacy) {
            return LegacyConversation::for($thread)['messages'];
        }

        $rows = ThreadTurnMessage::query()
            ->whereHas('taskRun', fn ($runs) => $runs->where('thread_id', $thread->id))
            ->with(['taskRun.task.attachments', 'taskRun.driver', 'pendingSteeringMessage.attachments', 'attachments'])
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

            // A message shows the files it carried; the task's unlinked request files belong to its first run.
            $files = $steer !== null
                ? $steer->attachments
                : $row->attachments->concat($firstRunIds->contains($run->id)
                    ? ($run->task?->attachments->where('context', TaskAttachment::CONTEXT_REQUEST)->whereNull('thread_turn_message_id') ?? [])
                    : [])->unique('id')->values();
            $attachments = array_values($files->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all());
            $text = $steer !== null ? $steer->text : ($row->text ?? (string) $run->task?->description);
            $references = array_values(array_filter(array_column($attachments, 'reference')));

            $authorName = $steer !== null ? $steer->author_name : $run->driver?->name;

            $messages[] = [
                'id' => 'turn-message-' . $row->id,
                'uuid' => $row->uuid,
                // The assembled prompt on task_runs is never shown; it carries Yak's own notes.
                'text' => $text,
                'authorName' => $authorName,
                'authorInitials' => self::initials($authorName),
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
                'bodyHtml' => AttachmentReferences::linkInHtml(Markdown::toHtml($text), $references),
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
                'authorName' => $queued->author_name,
                'authorInitials' => self::initials($queued->author_name),
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
     * @return array{id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, model: ?string, effort: ?string, state: 'pending'|'read'|'cancelled', canEditText: bool, intent: ?string, sendKey: ?string, mode: 'steer'|'queue'|null, sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}
     */
    private static function initialMessage(YakTask $task): array
    {
        $files = $task->attachments()->where('context', TaskAttachment::CONTEXT_REQUEST)->whereNull('thread_turn_message_id')->get();
        $attachments = array_values($files->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all());

        return [
            'id' => 'initial-message',
            'uuid' => null,
            'text' => (string) $task->description,
            'authorName' => $task->author_name,
            'authorInitials' => self::initials($task->author_name),
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

    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        return Str::upper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    }
}
