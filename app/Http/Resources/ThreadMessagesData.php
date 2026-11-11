<?php

namespace App\Http\Resources;

use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
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
     * @return list<array{id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, model: ?string, state: 'pending'|'read'|'cancelled', sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>, bodyHtml: string, attachments: list<array<string, mixed>>}>
     */
    public static function for(Thread $thread): array
    {
        $rows = ThreadTurnMessage::query()
            ->whereHas('taskRun', fn ($runs) => $runs->where('thread_id', $thread->id))
            ->with(['taskRun.task', 'taskRun.driver', 'pendingSteeringMessage.attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $messages = [];

        foreach ($rows as $row) {
            $run = $row->taskRun;

            if ($run === null) {
                continue;
            }

            $steer = $row->kind === TurnMessageKind::Steer ? $row->pendingSteeringMessage : null;

            // A steer whose pending row is gone has no text to show.
            if ($row->kind === TurnMessageKind::Steer && $steer === null) {
                continue;
            }

            $attachments = $steer !== null ? array_values($steer->attachments->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all()) : [];
            $text = $steer !== null ? $steer->text : (string) $run->task?->description;
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
                'state' => match ($row->state) {
                    TurnMessageState::Sent, TurnMessageState::Queued => 'pending',
                    TurnMessageState::Started, TurnMessageState::Completed => 'read',
                    TurnMessageState::Cancelled => 'cancelled',
                },
                'sentAt' => $row->created_at?->toIso8601String() ?? '',
                'readAfterSequence' => null,
                'bodyHtml' => AttachmentReferences::linkInHtml(Markdown::toHtml($text), $references),
                'attachments' => $attachments,
                'attachmentIds' => $steer !== null ? array_values($steer->attachments->pluck('id')->map(fn ($id): int => (int) $id)->all()) : [],
            ];
        }

        $root = $thread->tasks()->oldest()->first()?->rootTask();
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
                'state' => 'pending',
                'sentAt' => $queued->created_at?->toIso8601String() ?? '',
                'readAfterSequence' => null,
                'bodyHtml' => AttachmentReferences::linkInHtml(Markdown::toHtml($queued->text), array_values(array_filter(array_column($attachments, 'reference')))),
                'attachments' => $attachments,
                'attachmentIds' => array_values($queued->attachments->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            ];
        }

        return $messages;
    }

    private static function initials(?string $name): string
    {
        $words = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        return Str::upper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    }
}
