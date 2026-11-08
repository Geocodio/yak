<?php

namespace App\Services\Threads;

use App\Agents\StreamEventHandler;
use App\DataTransferObjects\StreamEntry;
use App\Enums\CommandState;
use App\Enums\NotificationType;
use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Events\RunnerExited;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Jobs\SendNotificationJob;
use App\Models\TaskRun;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Runs the turn state machine from the broker's output lines. Each entry is
 * applied in one transaction with the thread row locked and the thread's
 * out_cursor advanced, so a replayed entry changes nothing. Events fire after
 * the commit; completion_dispatched_at records that the event went out, and
 * tick() sends again any completion that was committed but never dispatched.
 */
class ThreadDriver
{
    /**
     * One log handler per running turn. A driver restart starts a fresh
     * handler, so its tool and assistant counters restart from that point.
     * ponytail: counters are added to the run as deltas; a pending tool log
     * open across a restart is not closed. Persist handler state if that matters.
     *
     * @var array<int, StreamEventHandler>
     */
    private array $handlers = [];

    /** @var list<int> threads whose runner was lost while a turn ran, recovered after the commit */
    private array $lostRunners = [];

    /** @var list<array{0: int, 1: string}> thread id and message of each turn the guard stopped, acted on after the commit */
    private array $stoppedTurns = [];

    public function __construct(private readonly RunnerRecovery $recovery, private readonly RunawayGuard $guard, private readonly ThreadCommands $commands) {}

    public function handle(StreamEntry $entry): void
    {
        $threadId = (int) substr($entry->channel, strlen('threads:'));
        /** @var list<object> $events */
        $events = [];

        DB::transaction(function () use ($entry, $threadId, &$events): void {
            $thread = Thread::query()->lockForUpdate()->find($threadId);

            if ($thread === null || ! $this->isNew($entry->id, $thread->out_cursor)) {
                return;
            }

            $this->apply($thread, $entry, $events);
            $thread->forceFill(['out_cursor' => $entry->id, 'last_activity_at' => now()])->save();
        });

        $this->dispatchAll($events);

        foreach (array_unique($this->lostRunners) as $lostThreadId) {
            $this->recovery->recover(Thread::query()->findOrFail($lostThreadId));
        }

        $this->lostRunners = [];
        $this->afterGuardStops();
    }

    /**
     * Stops running turns past their wall-clock limit, then sends the event
     * of every finished turn whose event never went out.
     */
    public function tick(): void
    {
        $this->guardRunningTurns();

        TaskRun::query()
            ->whereNotNull('thread_id')
            ->whereIn('turn_state', [TurnState::Completed, TurnState::Failed])
            ->whereNull('completion_dispatched_at')
            ->get()
            ->each(fn (TaskRun $run) => $this->dispatchAll([$this->completionEvent($run)]));
    }

    /**
     * @param  list<object>  $events
     */
    private function apply(Thread $thread, StreamEntry $entry, array &$events): void
    {
        $line = $entry->line;
        $type = $line['type'] ?? null;

        if ($type === 'broker') {
            $this->applyBrokerLine($thread, $entry, $line, $events);

            return;
        }

        $run = $thread->runningTurn();

        if ($type === 'runner') {
            if (($line['event'] ?? null) === 'started') {
                $thread->forceFill([
                    'runner_state' => $run === null ? RunnerState::Idle : RunnerState::Busy,
                    'runner_started_at' => now(),
                    'runner_generation' => $entry->generation,
                ])->save();
            }

            return;
        }

        if ($type === 'system' && ($line['subtype'] ?? null) === 'init' && is_string($line['session_id'] ?? null)) {
            $this->recordSession($thread, $line['session_id'], $run);
        }

        if ($run === null) {
            return;
        }

        $this->applyToTurn($thread, $run, $line, $events);
    }

    /**
     * A lost runner during a turn is recovered after the commit; with no turn
     * running the thread only forgets the runner. An exit from a runner older
     * than the current one is stale and changes nothing.
     *
     * @param  array<string, mixed>  $line
     * @param  list<object>  $events
     */
    private function applyBrokerLine(Thread $thread, StreamEntry $entry, array $line, array &$events): void
    {
        $event = $line['event'] ?? null;

        if ($event === 'runner_exited') {
            $generation = (int) ($line['generation'] ?? $entry->generation);

            if ($generation < $thread->runner_generation) {
                return;
            }

            $thread->forceFill(['runner_state' => RunnerState::None])->save();
            $events[] = new RunnerExited($thread, $generation, isset($line['exit_code']) ? (int) $line['exit_code'] : null);
        }

        $lost = match ($event) {
            'runner_exited', 'no_runner' => true,
            'broker_started' => $thread->runner_state !== RunnerState::Starting,
            default => false,
        };

        if ($lost && $thread->runningTurn() !== null) {
            $this->lostRunners[] = $thread->id;
        } elseif ($lost && $event === 'broker_started') {
            $thread->forceFill(['runner_state' => RunnerState::None])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<object>  $events
     */
    private function applyToTurn(Thread $thread, TaskRun $run, array $line, array &$events): void
    {
        $task = $run->task;

        if ($task === null) {
            return;
        }

        $handler = $this->handlers[$run->id] ??= new StreamEventHandler($task);
        $stats = $handler->getStats();
        $before = [$stats->assistantMessages, $stats->toolCalls, $stats->toolErrors];
        $handler->handle($line);

        $tracker = TurnTracker::fromRun($run);
        $changes = $tracker->observe($line);
        $attributes = $tracker->attributes();
        $tokens = $tracker->tokens();

        $run->forceFill($attributes + [
            'cost_usd' => $tracker->costUsd(),
            'assistant_messages' => $run->assistant_messages + $stats->assistantMessages - $before[0],
            'tool_calls' => $run->tool_calls + $stats->toolCalls - $before[1],
            'tool_errors' => $run->tool_errors + $stats->toolErrors - $before[2],
            'input_tokens' => $run->input_tokens + $tokens['input'],
            'output_tokens' => $run->output_tokens + $tokens['output'],
            'cache_read_tokens' => $run->cache_read_tokens + $tokens['cache_read'],
            'cache_creation_tokens' => $run->cache_creation_tokens + $tokens['cache_creation'],
        ]);

        $sessionId = (string) $run->session_id;
        if ($sessionId !== '' && isset($attributes['turn_cost_usd_by_session'][$sessionId])) {
            $run->session_cost_total_usd = $attributes['turn_cost_usd_by_session'][$sessionId]['total'];
        }

        foreach ($changes as $change) {
            if ($change->uuid === $run->prompt_uuid && $change->state === CommandState::Started) {
                $run->agent_started_at ??= now();
                $events[] = new TurnStarted($run);
            }
        }

        $run->save();

        if (! $tracker->isEnded()) {
            $this->guardTurn($thread, $run, $events);

            return;
        }

        $this->finishTurn($thread, $run, $tracker, $events);
    }

    /**
     * @param  list<object>  $events
     */
    private function finishTurn(Thread $thread, TaskRun $run, TurnTracker $tracker, array &$events): void
    {
        $lastResult = $run->last_result_event;
        $failed = is_array($lastResult) && ($lastResult['is_error'] ?? false) === true;
        $finishedAt = now();

        $changed = TaskRun::query()
            ->whereKey($run->id)
            ->whereIn('turn_state', [TurnState::Queued, TurnState::Running])
            ->update([
                'turn_state' => ($failed ? TurnState::Failed : TurnState::Completed)->value,
                'agent_finished_at' => $finishedAt,
                'agent_ms' => $run->agent_started_at === null ? null : (int) abs($finishedAt->diffInMilliseconds($run->agent_started_at)),
            ]);

        unset($this->handlers[$run->id]);
        $thread->forceFill([
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'lock_acquired_at' => null,
            'runner_state' => RunnerState::Idle,
        ])->save();

        if ($changed > 0) {
            $events[] = $this->completionEvent($run->fresh() ?? $run);
        }
    }

    private function guardRunningTurns(): void
    {
        $runs = TaskRun::query()
            ->whereNotNull('thread_id')
            ->where('turn_state', TurnState::Running)
            ->whereNotNull('agent_started_at')
            ->get();

        foreach ($runs as $candidate) {
            /** @var list<object> $events */
            $events = [];

            DB::transaction(function () use ($candidate, &$events): void {
                $thread = Thread::query()->lockForUpdate()->find($candidate->thread_id);
                $run = $thread?->runningTurn();

                if ($thread !== null && $run?->is($candidate)) {
                    $this->guardTurn($thread, $run, $events);
                }
            });

            $this->dispatchAll($events);
        }

        $this->afterGuardStops();
    }

    /**
     * @param  list<object>  $events
     */
    private function guardTurn(Thread $thread, TaskRun $run, array &$events): void
    {
        $reason = $this->guard->check($run);

        if ($reason === null) {
            return;
        }

        $run->forceFill([
            'turn_state' => TurnState::StoppedByGuard,
            'guard_reason' => $reason,
            'agent_finished_at' => now(),
        ])->save();

        unset($this->handlers[$run->id]);
        $thread->forceFill([
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'lock_acquired_at' => null,
            'runner_state' => RunnerState::Idle,
        ])->save();

        $this->stoppedTurns[] = [$thread->id, $this->guard->message($run, $reason)];
        $events[] = new TurnFailed($run);
    }

    private function afterGuardStops(): void
    {
        $stopped = $this->stoppedTurns;
        $this->stoppedTurns = [];

        foreach ($stopped as [$threadId, $message]) {
            $thread = Thread::query()->findOrFail($threadId);
            $this->commands->interrupt($thread);

            $task = $thread->turns()->where('turn_state', TurnState::StoppedByGuard)->latest('id')->first()?->task;

            if ($task !== null) {
                SendNotificationJob::dispatch($task, NotificationType::Progress, $message, personalize: false);
            }
        }
    }

    private function completionEvent(TaskRun $run): TurnCompleted|TurnFailed
    {
        return $run->turn_state === TurnState::Failed ? new TurnFailed($run) : new TurnCompleted($run);
    }

    /**
     * @param  list<object>  $events
     */
    private function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            event($event);

            if ($event instanceof TurnCompleted || $event instanceof TurnFailed) {
                TaskRun::query()->whereKey($event->run->id)->update(['completion_dispatched_at' => now()]);
            }
        }
    }

    private function recordSession(Thread $thread, string $sessionId, ?TaskRun $run): void
    {
        if ($thread->sessions()->where('session_id', $sessionId)->doesntExist()) {
            $latest = $thread->sessions()->orderByDesc('id')->first();

            // The init line carries no parent, so a new id after the first one
            // can only be the fresh session a /clear starts.
            $thread->sessions()->create([
                'session_id' => $sessionId,
                'parent_session_id' => $latest?->session_id,
                'kind' => $latest === null ? ThreadSessionKind::Main : ThreadSessionKind::Clear,
            ]);
        }

        $run?->task?->forceFill(['session_id' => $sessionId])->save();
        $run?->forceFill(['session_id' => $sessionId]);
    }

    /**
     * Redis stream ids compare as numeric (milliseconds, sequence) pairs.
     */
    private function isNew(string $id, ?string $cursor): bool
    {
        if ($cursor === null) {
            return true;
        }

        [$idMs, $idSeq] = array_map('intval', explode('-', $id . '-0'));
        [$cursorMs, $cursorSeq] = array_map('intval', explode('-', $cursor . '-0'));

        return [$idMs, $idSeq] > [$cursorMs, $cursorSeq];
    }
}
