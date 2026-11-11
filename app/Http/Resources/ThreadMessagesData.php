<?php

namespace App\Http\Resources;

use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use Illuminate\Support\Str;

/**
 * The person's messages in a thread, in send order, with whether the model has
 * taken each one in. State follows `thread_turn_messages`.
 */
final class ThreadMessagesData
{
    /**
     * @return list<array{id: string, uuid: ?string, text: string, authorName: ?string, authorInitials: string, model: ?string, state: 'pending'|'read'|'cancelled', sentAt: string, readAfterSequence: ?int, attachmentIds: list<int>}>
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

            $authorName = $steer !== null ? $steer->author_name : $run->driver?->name;

            $messages[] = [
                'id' => 'turn-message-' . $row->id,
                'uuid' => $row->uuid,
                // The assembled prompt on task_runs is never shown; it carries Yak's own notes.
                'text' => $steer !== null ? $steer->text : (string) $run->task?->description,
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
                'attachmentIds' => $steer !== null ? array_values($steer->attachments->pluck('id')->map(fn ($id): int => (int) $id)->all()) : [],
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
