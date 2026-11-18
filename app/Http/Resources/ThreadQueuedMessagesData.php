<?php

namespace App\Http\Resources;

use App\Enums\SteeringMode;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;

/**
 * Web only: the messages waiting for the thread's next turn, whatever channel they came from, with what
 * the viewer may do to each. Steered messages lead, as they reach Yak first.
 */
final class ThreadQueuedMessagesData
{
    /** @var array<string, string> */
    private const array SOURCE_LABELS = ['slack' => 'Slack', 'linear' => 'Linear', 'github_review' => 'GitHub'];

    /**
     * @return list<array{id: int, text: string, mode: 'steer'|'queue', source: string, sourceLabel: ?string, authorName: ?string, sentAt: string, canSteer: bool, canSwitch: bool, canRemove: bool, canEditText: bool, attachments: list<array<string, mixed>>}>
     */
    public static function for(Thread $thread, User $viewer): array
    {
        if ($thread->is_legacy || ! $thread->tasks()->exists()) {
            return [];
        }

        $canDrive = app(ThreadAccess::class)->canDrive($viewer, $thread);

        return PendingSteeringMessage::query()
            ->where('root_task_id', $thread->messagesRootTask()->id)
            ->whereNotExists(fn ($delivered) => $delivered->from('thread_turn_messages')
                ->where(fn ($match) => $match->whereColumn('thread_turn_messages.pending_steering_message_id', 'pending_steering_messages.id')
                    ->orWhereColumn('thread_turn_messages.uuid', 'pending_steering_messages.uuid')))
            ->with('attachments')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (PendingSteeringMessage $message): int => $message->mode === SteeringMode::Steer ? 0 : 1, SORT_REGULAR)
            ->map(fn (PendingSteeringMessage $message): array => [
                'id' => $message->id,
                'text' => $message->text,
                'mode' => $message->mode->value,
                'source' => $message->source,
                'sourceLabel' => self::SOURCE_LABELS[$message->source] ?? null,
                'authorName' => $message->author_name,
                'sentAt' => $message->created_at?->toIso8601String() ?? '',
                'canSteer' => $message->canSteer(),
                'canSwitch' => $canDrive,
                'canRemove' => $canDrive,
                'canEditText' => $canDrive && $message->source === 'thread' && $message->canEditTextBy($viewer),
                'attachments' => array_values($message->attachments->map(fn (TaskAttachment $attachment): array => AttachmentData::from($attachment))->all()),
            ])
            ->values()
            ->all();
    }
}
