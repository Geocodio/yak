<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\ClaudeAccounts\AccountConcurrency;
use Closure;
use Throwable;

/**
 * Ends a turn exactly once and announces the end. Only the call that wins the
 * conditional transition releases the thread lock and the account slot.
 */
class TurnFinisher
{
    public function __construct(
        private readonly AccountConcurrency $concurrency,
        private readonly ThreadSandbox $sandbox,
        private readonly SessionSync $sessions,
    ) {}

    /**
     * @param  list<object>  $events
     * @param  list<TurnState>  $from  states the turn may be ended from
     */
    public function finish(Thread $thread, TaskRun $run, TurnState $to, array &$events, array $from = [TurnState::Running]): bool
    {
        $was = $run->refresh()->getAttribute('turn_state');

        $changed = TaskRun::query()
            ->whereKey($run->id)
            ->whereIn('turn_state', $from)
            ->update(['turn_state' => $to, 'finished_at' => now()]);

        if ($changed !== 1) {
            return false;
        }

        $run->refresh();
        $account = $run->claudeAccount;
        $wasRunning = $was === TurnState::Running;

        // A turn that never got a slot holds none.
        if ($account !== null && ! in_array($was, [TurnState::Pending, TurnState::WaitingForAccount], true)) {
            $this->concurrency->release($account, $run);
        }

        $thread->forceFill([
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'last_activity_at' => now(),
        ] + ($wasRunning ? ['runner_state' => RunnerState::Idle] : []));

        $events[] = $to === TurnState::Completed ? new TurnCompleted($run) : new TurnFailed($run);

        if ($wasRunning) {
            $events[] = function () use ($thread): void {
                $this->isolated(fn () => $this->sessions->syncFromSandbox($thread));
                $this->isolated(fn () => $this->sandbox->pauseIfIdle($thread));
            };
        }

        return true;
    }

    /**
     * Fires events after the commit. A turn end is marked emitted only once its
     * event went out, so a crash in between re-dispatches it from the driver's
     * tick; a closure is follow-up work that must not break the dispatch.
     *
     * @param  list<object>  $events
     */
    public function dispatch(array $events): void
    {
        foreach ($events as $event) {
            if ($event instanceof Closure) {
                $this->isolated($event);

                continue;
            }

            event($event);

            if ($event instanceof TurnCompleted || $event instanceof TurnFailed) {
                TaskRun::query()->whereKey($event->run->id)->whereNull('completion_event_emitted_at')->update(['completion_event_emitted_at' => now()]);
            }
        }
    }

    /**
     * @param  Closure(): void  $step
     */
    private function isolated(Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
