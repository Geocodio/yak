<?php

namespace App\Listeners;

use App\Events\ThreadDiffUpdated;
use App\Services\Threads\ThreadEventPublisher;
use Throwable;

/**
 * Tells open clients that the thread's stored diff was replaced; the payload is empty,
 * clients reload the diff. A failure to publish is reported and never fails the capture.
 */
class PublishDiffUpdated
{
    public function __construct(private readonly ThreadEventPublisher $publisher) {}

    public function handle(ThreadDiffUpdated $event): void
    {
        try {
            $this->publisher->append($event->thread->id, 'diff.updated', []);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
