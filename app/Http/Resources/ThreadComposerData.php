<?php

namespace App\Http\Resources;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTurns;

/**
 * What the composer needs: whether it can send, the model in use, the models
 * to pick from and the number the next attachment label gets.
 */
final class ThreadComposerData
{
    /** Blocks that cannot be waited out by typing; the others keep the message for later. */
    private const array DISABLING_BLOCKS = [
        ThreadBlockReason::AccountInvalid,
        ThreadBlockReason::AccountExpired,
        ThreadBlockReason::CredentialExposure,
        ThreadBlockReason::RestoreFailed,
        ThreadBlockReason::WaitingForRepository,
        ThreadBlockReason::RepositorySetupFailed,
    ];

    /**
     * @return array{state: 'idle'|'working'|'disabled_closed'|'disabled_computer'|'disabled_blocked'|'readonly', model: ?string, models: list<array{family: string, latest: array{value: string, label: string, description: string}, older: list<array{value: string, label: string}>}>, accountName: ?string, nextAttachmentNumber: int}
     */
    public static function for(Thread $thread, ?User $viewer): array
    {
        $state = match (true) {
            ! app(ThreadAccess::class)->canDrive($viewer, $thread) => 'readonly',
            ! $thread->isOpen() => 'disabled_closed',
            $thread->owner_side === ThreadOwnerSide::Device => 'disabled_computer',
            in_array($thread->blocked_reason, self::DISABLING_BLOCKS, true) => 'disabled_blocked',
            ThreadTurns::activeTurn($thread) !== null => 'working',
            default => 'idle',
        };

        $taskIds = $thread->tasks()->pluck('id');
        $attachments = TaskAttachment::query()
            ->whereIn('yak_task_id', $taskIds)
            ->orWhereIn('pending_steering_message_id', fn ($pending) => $pending->select('id')->from('pending_steering_messages')->whereIn('root_task_id', $taskIds))
            ->count();

        return [
            'state' => $state,
            'model' => $thread->model,
            'models' => app(ClaudeModelCatalog::class)->families(),
            'accountName' => $thread->claudeAccount?->name,
            'nextAttachmentNumber' => $attachments + 1,
        ];
    }
}
