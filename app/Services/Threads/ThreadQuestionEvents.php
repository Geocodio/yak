<?php

namespace App\Services\Threads;

use App\Models\YakTask;
use Throwable;

/**
 * Tells connected clients that a thread's open question appeared or went away, so they refetch
 * the thread. A failure to store the event is reported and never fails the caller.
 */
class ThreadQuestionEvents
{
    public function __construct(private readonly ThreadEventPublisher $publisher) {}

    public function opened(YakTask $task): void
    {
        $this->emit($task, true);
    }

    public function closed(YakTask $task): void
    {
        $this->emit($task, false);
    }

    private function emit(YakTask $task, bool $isOpen): void
    {
        if ($task->thread_id === null) {
            return;
        }

        try {
            $this->publisher->append($task->thread_id, 'question', ['open' => $isOpen]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
