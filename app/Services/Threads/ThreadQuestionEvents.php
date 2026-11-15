<?php

namespace App\Services\Threads;

use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
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

        $threadId = $task->thread_id;

        // After the surrounding transaction commits, so a client reloading on the event sees the change.
        DB::afterCommit(function () use ($threadId, $isOpen): void {
            try {
                $this->publisher->append($threadId, 'question', ['open' => $isOpen]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
