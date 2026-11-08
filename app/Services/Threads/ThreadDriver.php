<?php

namespace App\Services\Threads;

use App\Agents\StreamEventHandler;
use App\DataTransferObjects\StreamEntry;
use App\Enums\CommandState;
use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Events\RunnerExited;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
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
    }

    /**
     * Sends the event of every finished turn whose event never went out.
     */
    public function tick(): void
    {
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
            if (($line['event'] ?? null) === 'runner_exited') {
                $thread->forceFill(['runner_state' => RunnerState::None])->save();
                $events[] = new RunnerExited($thread, (int) ($line['generation'] ?? $entry->generation), isset($line['exit_code']) ? (int) $line['exit_code'] : null);
            }

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
