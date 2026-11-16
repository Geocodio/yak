<?php

namespace App\Listeners;

use App\Events\ThreadRepositoryChanged;
use App\Services\Threads\Repos\RepositoryCardPublisher;

/**
 * Cards exist for repositories Claude added mid-thread; repositories chosen at the start
 * show in the Environment panel instead.
 */
class PublishRepositoryCard
{
    public function __construct(private readonly RepositoryCardPublisher $cards) {}

    public function handle(ThreadRepositoryChanged $event): void
    {
        if ($event->repository->added_by === 'claude') {
            $this->cards->publish($event->repository);
        }
    }
}
