<?php

namespace App\Listeners;

use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Services\Threads\ThreadShowChanged;

/**
 * A task entering or leaving a state a card above the composer shows (a question, a co-owner
 * hold, a stop, a cancel) refreshes the pages open on its thread, so a retry, a cancel, a stop
 * and a repository answer reach every viewer.
 */
class PublishThreadShowChanged
{
    private const array CARD_STATUSES = [
        TaskStatus::AwaitingClarification,
        TaskStatus::AwaitingCoOwner,
        TaskStatus::Failed,
        TaskStatus::Expired,
        TaskStatus::Cancelled,
    ];

    public function handle(TaskStatusChanged $event): void
    {
        if (in_array($event->from, self::CARD_STATUSES, true) || in_array($event->to, self::CARD_STATUSES, true)) {
            ThreadShowChanged::publish($event->task->thread_id);
        }
    }
}
