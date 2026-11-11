<?php

namespace App\Services\Threads;

use App\Agents\StreamEventHandler;
use App\DataTransferObjects\StreamEntry;
use App\DataTransferObjects\TurnTrackerChange;
use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadStatus;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\RunnerExited;
use App\Events\ThreadActivity;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Models\ThreadTurnMessage;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\TaskLogger;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Support\SecretRedactor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies broker stream entries to threads. Each entry runs in one transaction
 * with the thread row locked and the applied (generation, sequence) cursor
 * advanced, so a redelivered entry changes nothing. Events fire after the commit.
 */
class ThreadDriver
{
    /**
     * One log handler per running turn, keyed by run id.
     *
     * @var array<int, StreamEventHandler>
     */
    private array $handlers = [];

    public function __construct(
        private readonly ThreadCommands $commands,
        private readonly YakToolRouter $tools,
        private readonly SecretRedactor $redactor,
        private readonly BrokerStreams $streams,
        private readonly RunawayGuard $guard,
        private readonly TurnFinisher $finisher,
        private readonly RunnerRecovery $recovery,
    ) {}

    public function handle(StreamEntry $entry): void
    {
        if (! str_starts_with($entry->channel, 'threads:')) {
            return;
        }

        $threadId = (int) substr($entry->channel, strlen('threads:'));
        /** @var list<object> $events */
        $events = [];

        DB::transaction(function () use ($entry, $threadId, &$events): void {
            $thread = Thread::query()->lockForUpdate()->find($threadId);

            if ($thread === null || ! $this->isNew($thread, $entry)) {
                return;
            }

            $this->apply($thread, $entry, $events);
            $thread->forceFill([
                'applied_generation' => $entry->generation,
                'applied_sequence' => $entry->sequence,
                'last_activity_at' => now(),
            ])->save();
        });

        $this->dispatchAll($events);
    }

    /**
     * Fails the running turn of the thread an entry belongs to.
     */
    public function failTurnOf(StreamEntry $entry): void
    {
        if (! str_starts_with($entry->channel, 'threads:')) {
            return;
        }

        $this->withLockedThread((int) substr($entry->channel, strlen('threads:')), function (Thread $thread, array &$events): void {
            $run = ThreadTurns::runningTurn($thread);

            if ($run !== null) {
                $this->finishTurn($thread, $run, TurnState::Failed, $events);
            }
        });
    }

    /**
     * Periodic work between reads: re-announces turn ends whose event was never
     * dispatched, ends turns whose grace window closed and stops runaway turns.
     * Each step is isolated so one bad turn cannot starve the rest.
     */
    public function tick(): void
    {
        TaskRun::query()
            ->whereNotNull('thread_id')
            ->whereIn('turn_state', [TurnState::Completed, TurnState::Failed, TurnState::StoppedByGuard])
            ->whereNull('completion_event_emitted_at')
            ->get()
            ->each(fn (TaskRun $run) => $this->isolated(fn () => $this->dispatchAll([$this->endEventFor($run)])));

        TaskRun::query()
            ->whereNotNull('thread_id')
            ->where('turn_state', TurnState::Running)
            ->get()
            ->each(fn (TaskRun $run) => $this->isolated(fn () => $this->tickTurn($run)));

        $this->isolated(fn () => $this->recovery->recoverDue());
        $this->isolated(fn () => app(ThreadTurns::class)->resumeWaiting());
    }

    /**
     * Trims the channel's outbound stream to the catch-up window, never past
     * the entry that was just acknowledged.
     */
    public function trimAfterAck(StreamEntry $entry): void
    {
        $cutoff = now()->subMinutes((int) config('yak.threads.stream_catchup_minutes'))->getTimestampMs() . '-0';

        [$cutoffMs, $cutoffSequence] = array_map('intval', explode('-', $cutoff));
        [$entryMs, $entrySequence] = array_map('intval', explode('-', $entry->id) + [1 => 0]);

        $this->streams->trimBefore($entry->channel, [$cutoffMs, $cutoffSequence] > [$entryMs, $entrySequence] ? $entry->id : $cutoff);
    }

    private function tickTurn(TaskRun $run): void
    {
        $this->withLockedThread((int) $run->thread_id, function (Thread $thread, array &$events) use ($run): void {
            $run = ThreadTurns::runningTurn($thread);

            if ($run === null) {
                return;
            }

            $tracker = $this->trackerFor($run);
            $this->isolated(function () use ($thread, $run, $tracker, &$events): void {
                $this->applyChange($thread, $run, $tracker, $tracker->tick(CarbonImmutable::now()), $events);
            });

            $reason = $run->refresh()->getAttribute('turn_state') === TurnState::Running
                ? $this->guard->check($run, CarbonImmutable::now(), (int) $run->getAttribute('assistant_turns'))
                : null;

            if ($reason !== null) {
                $this->commands->interrupt($thread);

                if ($run->task !== null) {
                    TaskLogger::warning($run->task, 'turn_stopped_by_guard', ['reason' => $reason]);
                }

                $this->finishTurn($thread, $run, TurnState::StoppedByGuard, $events);
            }
        });
    }

    /**
     * Runs the work in one transaction with the thread row locked, then fires
     * the collected events.
     *
     * @param  Closure(Thread, list<object>): void  $work
     */
    private function withLockedThread(int $threadId, Closure $work): void
    {
        /** @var list<object> $events */
        $events = [];

        DB::transaction(function () use ($threadId, $work, &$events): void {
            $thread = Thread::query()->lockForUpdate()->find($threadId);

            if ($thread === null) {
                return;
            }

            $work($thread, $events);
            $thread->save();
        });

        $this->dispatchAll($events);
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

    private function isNew(Thread $thread, StreamEntry $entry): bool
    {
        return [$entry->generation, $entry->sequence] > [(int) $thread->getAttribute('applied_generation'), (int) $thread->getAttribute('applied_sequence')];
    }

    /**
     * @param  list<object>  $events
     */
    private function apply(Thread $thread, StreamEntry $entry, array &$events): void
    {
        $line = $entry->line;

        // Only the broker's own stream field marks a broker line; a runner line that claims to be one is dropped.
        if ($entry->fromBroker) {
            $this->applyBrokerLine($thread, $entry, $events);

            return;
        }

        if (str_starts_with((string) ($line['type'] ?? ''), 'broker')) {
            return;
        }

        $isStarted = BrokerLine::runnerEvent($line) === 'started';

        if ($isStarted ? $entry->generation < $thread->runner_generation : $entry->generation !== $thread->runner_generation) {
            return;
        }

        $run = ThreadTurns::activeTurn($thread);

        if ($isStarted) {
            $thread->forceFill([
                'runner_state' => $run === null ? RunnerState::Idle : RunnerState::Busy,
                'runner_generation' => $entry->generation,
                'runner_started_at' => now(),
            ]);
        }

        $line = json_decode($this->redactor::redact(json_encode($line, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)), true) ?? [];

        if (($line['type'] ?? null) === 'yak.tool_call') {
            $this->answerToolCall($thread, $entry, $line);

            return;
        }

        if ($run !== null && $run->task !== null) {
            ($this->handlers[$run->id] ??= new StreamEventHandler($run->task))->handle($line);
        }

        if ($run !== null) {
            $this->applyToTurn($thread, $run, $line, CarbonImmutable::now(), $events);
        }

        $events[] = new ThreadActivity($thread, $entry->generation, $entry->sequence, $line);
    }

    /**
     * @param  list<object>  $events
     */
    private function applyBrokerLine(Thread $thread, StreamEntry $entry, array &$events): void
    {
        $line = $entry->line;
        $event = BrokerLine::event($line);

        if ($event === 'no_runner' || $event === 'broker_started' || $event === 'restarted') {
            $this->recoverLostRunner($thread, $entry, $line, $events);
        }

        if ($event === 'line_dropped') {
            $task = ThreadTurns::activeTurn($thread)?->task;

            if ($task !== null) {
                TaskLogger::warning($task, 'runner_line_dropped', ['bytes' => (int) ($line['bytes'] ?? 0)]);
            }
        }

        if ($event === 'runner_exited') {
            $generation = (int) ($line['generation'] ?? $entry->generation);

            if ($generation !== $thread->runner_generation) {
                return;
            }

            $endedForGood = ThreadTurns::activeTurn($thread) === null
                && ($thread->runner_state === RunnerState::Stopping || $thread->status === ThreadStatus::Closed);

            $thread->forceFill(['runner_state' => RunnerState::None]);
            $this->commands->revokePlaceholder($thread);

            if (! $endedForGood) {
                $this->recovery->recover($thread, $events);
            }

            if ($endedForGood) {
                $events[] = function () use ($entry): void {
                    $this->streams->delete($entry->channel);
                    $this->streams->unregister($entry->channel);
                };
            }
            $events[] = new RunnerExited($thread, $generation, isset($line['exit_code']) ? (int) $line['exit_code'] : null);
        }

        $events[] = new ThreadActivity($thread, $entry->generation, $entry->sequence, $line);
    }

    /**
     * A broker restart, or a message for a runner that is not there, means the
     * runner of a running turn is gone. A no_runner line only counts for a uuid
     * the turn still waits on and for the current generation.
     *
     * @param  array<string, mixed>  $line
     * @param  list<object>  $events
     */
    private function recoverLostRunner(Thread $thread, StreamEntry $entry, array $line, array &$events): void
    {
        $run = ThreadTurns::runningTurn($thread);

        if ($run === null || ! in_array($thread->runner_state, [RunnerState::Idle, RunnerState::Busy], true)) {
            return;
        }

        if (BrokerLine::event($line) === 'no_runner') {
            $isOutstanding = $entry->generation >= $thread->runner_generation
                && ThreadTurnMessage::query()
                    ->where('task_run_id', $run->id)
                    ->where('uuid', (string) ($line['uuid'] ?? ''))
                    ->whereIn('state', [TurnMessageState::Sent, TurnMessageState::Queued])
                    ->exists();

            if (! $isOutstanding) {
                return;
            }
        }

        $this->commands->revokePlaceholder($thread);
        $this->recovery->recover($thread, $events);
    }

    /**
     * Runs a Yak tool once per call id and sends the result to the runner. A
     * redelivered call that was already answered gets nothing.
     *
     * @param  array<string, mixed>  $line
     */
    private function answerToolCall(Thread $thread, StreamEntry $entry, array $line): void
    {
        $call = YakToolCall::fromLine($line);

        if ($call === null) {
            return;
        }

        DB::table('thread_tool_calls')->insertOrIgnore([
            'thread_id' => $thread->id,
            'call_id' => $call->callId,
            'generation' => $entry->generation,
            'tool' => $call->tool,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = DB::table('thread_tool_calls')->where('thread_id', $thread->id)->where('call_id', $call->callId);

        if ((clone $record)->value('answered_at') !== null) {
            return;
        }

        try {
            $result = $this->tools->handle($thread, $call);
        } catch (Throwable $exception) {
            report($exception);
            $result = new YakToolResult(true, $this->redactor::redact($exception->getMessage()));
        }

        $this->commands->toolResult($thread, $call->callId, $result->isError, $result->content);
        $record->update(['answered_at' => now()]);
    }

    /**
     * @param  list<object>  $events
     */
    private function dispatchAll(array $events): void
    {
        $this->finisher->dispatch($events);
    }

    private function endEventFor(TaskRun $run): object
    {
        return $run->getAttribute('turn_state') === TurnState::Completed ? new TurnCompleted($run) : new TurnFailed($run);
    }

    /**
     * Feeds one runner line to the turn's tracker and applies what it reports.
     *
     * @param  array<string, mixed>  $line
     * @param  list<object>  $events
     */
    private function applyToTurn(Thread $thread, TaskRun $run, array $line, CarbonImmutable $at, array &$events): void
    {
        $tracker = $this->trackerFor($run);
        $type = $line['type'] ?? null;

        if ($type === 'assistant') {
            $run->setAttribute('assistant_turns', (int) $run->getAttribute('assistant_turns') + 1);
        }

        if ($type === 'result') {
            $run->setAttribute('last_result_event', $line);
        }

        if ($type === 'system' && ($line['subtype'] ?? null) === 'init' && isset($line['session_id'])) {
            $run->setAttribute('session_id', (string) $line['session_id']);
            ThreadSession::query()->firstOrCreate(
                ['thread_id' => $thread->id, 'session_id' => (string) $line['session_id']],
                ['kind' => ThreadSessionKind::Main],
            );
        }

        $this->applyChange($thread, $run, $tracker, $tracker->apply($line, $at), $events);
    }

    private function trackerFor(TaskRun $run): TurnTracker
    {
        return ThreadTurns::trackerFor($run);
    }

    /**
     * @param  list<object>  $events
     */
    private function applyChange(Thread $thread, TaskRun $run, TurnTracker $tracker, TurnTrackerChange $change, array &$events): void
    {
        $run->setAttribute('tracker_state', json_encode($tracker->state(), JSON_THROW_ON_ERROR));
        $run->setAttribute('recorded_cost_usd', round((float) $run->getAttribute('recorded_cost_usd') + $change->costDeltaUsd, 4));

        foreach ($change->uuidStates as $uuid => $state) {
            $this->moveMessage($run, (string) $uuid, $state);

            if ($state === TurnMessageState::Started && (string) $uuid === (string) $run->getAttribute('prompt_uuid') && $run->agent_started_at === null) {
                $run->forceFill(['agent_started_at' => now(), 'turn_state' => TurnState::Running]);
                $thread->forceFill(['runner_state' => RunnerState::Busy]);
                $events[] = new TurnStarted($run);
            }
        }

        $run->save();

        $reason = $change->failure === null ? null : $this->blockReasonFor($change->failure);

        if ($reason !== null) {
            $thread->forceFill(['blocked_reason' => $reason, 'blocked_at' => now()]);

            if ($change->failure->kind === AccountFailureKind::AuthFailed) {
                $thread->claudeAccount?->markInvalid('The Claude API rejected the credential.');
            }

            $this->commands->interrupt($thread);
            $this->finishTurn($thread, $run, TurnState::Failed, $events);

            return;
        }

        if ($change->ended && ! $change->waitingOnBackgroundTask) {
            $this->finishTurn($thread, $run, TurnState::Completed, $events);
        }
    }

    private function moveMessage(TaskRun $run, string $uuid, TurnMessageState $state): void
    {
        $message = ThreadTurnMessage::query()->where('task_run_id', $run->id)->where('uuid', $uuid)->first();

        if ($message === null) {
            return;
        }

        $message->forceFill(['state' => $state])->save();

        $column = match ($state) {
            TurnMessageState::Queued => 'delivered_at',
            TurnMessageState::Started => 'absorbed_at',
            default => null,
        };

        if ($column !== null && $message->pending_steering_message_id !== null) {
            PendingSteeringMessage::query()->whereKey($message->pending_steering_message_id)->whereNull($column)->update([$column => now()]);
        }
    }

    /**
     * The one place an account failure becomes a thread block reason; null
     * means the failure is transient and the turn carries on.
     */
    private function blockReasonFor(AccountFailure $failure): ?ThreadBlockReason
    {
        return $failure->kind->blockReason();
    }

    /**
     * @param  list<object>  $events
     */
    private function finishTurn(Thread $thread, TaskRun $run, TurnState $to, array &$events): bool
    {
        unset($this->handlers[$run->id]);

        return $this->finisher->finish($thread, $run, $to, $events);
    }
}
