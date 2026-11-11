<?php

namespace App\Listeners;

use App\Enums\ThreadStatus;
use App\Events\RunnerExited;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;

/**
 * Publishes turn state, the state of the turn's messages and the thread state as realtime
 * events. When the runner of a closed thread has exited, its catch-up window is dropped and
 * the database answers from then on.
 */
class PublishTurnLifecycle
{
    public function __construct(
        private readonly ThreadEventPublisher $publisher,
        private readonly ThreadEventWindow $window,
    ) {}

    public function handle(TurnStarted|TurnCompleted|TurnFailed|RunnerExited $event): void
    {
        if ($event instanceof RunnerExited) {
            $this->publisher->appendThreadState($event->thread->id);

            if ($event->thread->status === ThreadStatus::Closed) {
                $this->window->forget($event->thread->id);
            }

            return;
        }

        $run = $event->run;

        if ($run->thread_id === null) {
            return;
        }

        $this->publisher->append($run->thread_id, 'turn', ['turnId' => $run->id, 'state' => $run->turn_state->value]);

        foreach (ThreadTurnMessage::query()->where('task_run_id', $run->id)->orderBy('id')->get() as $message) {
            $this->publisher->append($run->thread_id, 'message.state', ['uuid' => $message->uuid, 'state' => $message->state->value]);
        }
    }
}
