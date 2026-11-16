<?php

namespace App\Services\Teleport;

use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Models\Teleport;
use App\Models\Thread;

/**
 * Yak runs turns only on threads it owns and that no teleport has open. The first turn after
 * teleport up starts once the teleport is completed, so it passes.
 */
class ThreadOwnership
{
    public function yakMayRunTurns(Thread $thread): bool
    {
        return $thread->owner_side === ThreadOwnerSide::Yak
            && ! Teleport::query()->where('thread_id', $thread->id)->whereIn('state', TeleportState::nonTerminal())->exists();
    }

    /**
     * @throws ThreadNotOwnedByYak
     */
    public function assertYakMayRunTurns(Thread $thread): void
    {
        if (! $this->yakMayRunTurns($thread)) {
            throw new ThreadNotOwnedByYak("Thread {$thread->id} is on a computer or in a teleport.");
        }
    }
}
