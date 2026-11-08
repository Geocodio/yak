<?php

namespace App\Services\Threads;

use App\Agents\StreamEventHandler;
use App\DataTransferObjects\StreamEntry;
use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\ClaudeAccountStatus;
use App\Enums\CommandState;
use App\Enums\NotificationType;
use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Events\RunnerExited;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Jobs\SendNotificationJob;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\AccountFailureClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs the turn state machine from the broker's output lines. Each entry is
 * applied in one transaction with the thread row locked and the thread's
 * out_cursor advanced, so a replayed entry changes nothing. Events fire after
 * the commit; completion_dispatched_at records that the event went out, and
 * tick() sends again any completion that was committed but never dispatched.
 */
class ThreadDriver
{
    /** Automatic runner restarts allowed within one turn; the next loss fails the turn. */
    public const MAX_RESTARTS = 3;

    /** Seconds to wait before the first, second and third restart. */
    private const RESTART_BACKOFF_SECONDS = [0, 5, 30];

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

    /** @var list<int> threads whose turn completed or failed, paused after the commit */
    private array $finishedThreads = [];

    public function __construct(private readonly RunnerRecovery $recovery, private readonly RunawayGuard $guard, private readonly ThreadCommands $commands, private readonly ThreadSandbox $sandbox, private readonly AccountFailureClassifier $classifier) {}

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

        $lost = array_unique($this->lostRunners);
        $this->lostRunners = [];

        foreach ($lost as $lostThreadId) {
            $this->restartIfDue($lostThreadId);
        }

        $this->runPendingEffects();
        $this->pauseFinishedThreads();
    }

    /**
     * Fails the running turn of the thread an entry belongs to. Used when an
     * entry cannot be handled after repeated attempts: only that thread's turn
     * ends, and its lock is released.
     */
    public function failTurnOf(StreamEntry $entry): void
    {
        $threadId = (int) substr($entry->channel, strlen('threads:'));
        /** @var list<object> $events */
        $events = [];

        DB::transaction(function () use ($threadId, &$events): void {
            $thread = Thread::query()->lockForUpdate()->find($threadId);
            $run = $thread?->runningTurn();

            if ($thread !== null && $run !== null) {
                $this->abandonTurn($thread, $run, $events);
            }
        });

        $this->dispatchAll($events);

        $this->runPendingEffects();
        $this->pauseFinishedThreads();
    }

    /**
     * Stops running turns past their wall-clock limit, then sends the event
     * of every finished turn whose event never went out.
     */
    public function tick(): void
    {
        foreach (['guardRunningTurns', 'sendWaitingSteers', 'restartWaitingRunners', 'runPendingEffects'] as $step) {
            $this->isolated(fn () => $this->{$step}());
        }

        TaskRun::query()
            ->whereNotNull('thread_id')
            ->whereIn('turn_state', [TurnState::Completed, TurnState::Failed])
            ->whereNull('completion_dispatched_at')
            ->get()
            ->each(fn (TaskRun $run) => $this->isolated(fn () => $this->dispatchAll([$this->completionEvent($run)])));
    }

    /**
     * One failing thread must not stop the rest of a tick, so each operation reports its own failure.
     *
     * @param  callable(): mixed  $operation
     */
    private function isolated(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  list<object>  $events
     */
    private function apply(Thread $thread, StreamEntry $entry, array &$events): void
    {
        $line = $entry->line;
        $type = $line['type'] ?? null;

        // Only the broker's own stream field marks a broker line. A line body that
        // claims to be from the broker came from the runner and is ignored.
        if ($entry->fromBroker) {
            $this->applyBrokerLine($thread, $entry, $line, $events);

            return;
        }

        if ($type === 'broker') {
            return;
        }

        $run = $thread->runningTurn();

        // Output of a runner that is not the current one must not touch the current turn.
        // The `started` line is how a new generation becomes current.
        $isStarted = $type === 'runner' && ($line['event'] ?? null) === 'started';

        if ($isStarted ? $entry->generation < $thread->runner_generation : $entry->generation !== $thread->runner_generation) {
            return;
        }

        if ($type === 'runner') {
            if ($isStarted) {
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
     * A lost runner during a turn is restarted after the commit, after a backoff
     * and at most MAX_RESTARTS times; with no turn running the thread only forgets
     * the runner. A signal from a runner other than the current one is stale.
     *
     * @param  array<string, mixed>  $line
     * @param  list<object>  $events
     */
    private function applyBrokerLine(Thread $thread, StreamEntry $entry, array $line, array &$events): void
    {
        $event = $line['event'] ?? null;

        if (! in_array($event, ['runner_exited', 'no_runner', 'broker_started'], true) || ! $this->isCurrentRunnerSignal($thread, $entry->generation)) {
            return;
        }

        $run = $thread->runningTurn();

        if ($event === 'runner_exited') {
            $thread->forceFill(['runner_state' => RunnerState::None])->save();
            $this->commands->revokePlaceholder($thread);
            $events[] = new RunnerExited($thread, $entry->generation, isset($line['exit_code']) ? (int) $line['exit_code'] : null);
        } elseif ($event === 'no_runner') {
            // Only a command this turn is still waiting on can have been refused.
            $uuid = $line['uuid'] ?? null;

            if ($run === null || ! is_string($uuid) || ! $this->isOutstanding($run, $uuid)) {
                return;
            }
        } elseif ($thread->runner_state === RunnerState::Starting) {
            return;
        }

        if ($run === null) {
            $thread->forceFill(['runner_state' => RunnerState::None])->save();

            return;
        }

        $this->scheduleRestart($thread, $run, $events);
    }

    /**
     * A signal about the runner that is current. While a start is pending the
     * old runner's exit is expected, so only a newer generation counts.
     */
    private function isCurrentRunnerSignal(Thread $thread, int $generation): bool
    {
        return $thread->runner_state === RunnerState::Starting
            ? $generation > $thread->runner_generation
            : $generation >= $thread->runner_generation;
    }

    private function isOutstanding(TaskRun $run, string $uuid): bool
    {
        $state = $run->command_states?->get($uuid);

        return $state !== null && ! in_array($state, [CommandState::Completed, CommandState::Cancelled], true);
    }

    /**
     * @param  list<object>  $events
     */
    private function scheduleRestart(Thread $thread, TaskRun $run, array &$events): void
    {
        // A restart is already waiting for this loss; further signals about it add nothing.
        if ($run->restart_due_at !== null) {
            return;
        }

        $restarts = $run->restart_count + 1;

        if ($restarts > self::MAX_RESTARTS) {
            $this->abandonTurn($thread, $run, $events);

            return;
        }

        $run->forceFill([
            'restart_count' => $restarts,
            'restart_due_at' => now()->addSeconds(self::RESTART_BACKOFF_SECONDS[$restarts - 1]),
        ])->save();

        // The new runner never saw these; recovery sends them again with their uuids.
        PendingSteeringMessage::query()
            ->where('task_run_id', $run->id)
            ->whereNull('absorbed_at')
            ->update(['sent_at' => null, 'delivered_at' => null]);
        $this->lostRunners[] = $thread->id;
    }

    /**
     * The runner keeps dying: fail the turn and release the lock instead of restarting forever.
     *
     * @param  list<object>  $events
     */
    private function abandonTurn(Thread $thread, TaskRun $run, array &$events): void
    {
        $run->forceFill(['turn_state' => TurnState::Failed, 'agent_finished_at' => now(), 'restart_due_at' => null, 'pending_effects' => ['runner' => 'kill']])->save();
        unset($this->handlers[$run->id]);

        $thread->forceFill(['runner_state' => RunnerState::None] + ($thread->lock_turn_id === $run->id ? [
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'lock_acquired_at' => null,
        ] : []))->save();

        $this->finishedThreads[] = $thread->id;
        $events[] = new TurnFailed($run);
    }

    /**
     * Restarts the runner of a turn whose backoff has passed. The claim is a
     * conditional update, so a restart happens once however many callers race.
     */
    private function restartIfDue(int $threadId): void
    {
        $thread = Thread::query()->findOrFail($threadId);
        $run = $thread->runningTurn();

        if ($run === null) {
            return;
        }

        $claimed = TaskRun::query()
            ->whereKey($run->id)
            ->whereNotNull('restart_due_at')
            ->where('restart_due_at', '<=', now())
            ->update(['restart_due_at' => null]);

        if ($claimed !== 1) {
            return;
        }

        if (ClaudeAccount::query()->find($thread->claude_account_id)?->isUsable() === true) {
            $this->recovery->recover($thread);

            return;
        }

        $this->failForUnusableAccount($thread->id);
    }

    /**
     * The account was deleted or became unusable while the runner was down:
     * nothing can restart it, so the turn fails and the lock is released.
     */
    private function failForUnusableAccount(int $threadId): void
    {
        /** @var list<object> $events */
        $events = [];

        DB::transaction(function () use ($threadId, &$events): void {
            $thread = Thread::query()->lockForUpdate()->find($threadId);
            $run = $thread?->runningTurn();

            if ($thread !== null && $run !== null) {
                $thread->forceFill(['blocked_reason' => ThreadBlockReason::NoValidAccount]);
                $this->abandonTurn($thread, $run, $events);
            }
        });

        $this->dispatchAll($events);
        $this->runPendingEffects();
        $this->pauseFinishedThreads();
    }

    private function restartWaitingRunners(): void
    {
        TaskRun::query()
            ->whereNotNull('thread_id')
            ->where('turn_state', TurnState::Running)
            ->whereNotNull('restart_due_at')
            ->pluck('thread_id')
            ->unique()
            ->each(fn (int $threadId) => $this->restartIfDue($threadId));
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

        $this->recordUsageLimitReset($thread, $line);
        $failure = $this->classifier->classify($line);

        if ($failure !== null) {
            $this->blockTurn($thread, $run, $failure, $events);

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

        foreach ($changes as $change) {
            $this->applySteeringChange($task, $change);
        }

        if (! $tracker->isEnded()) {
            $this->guardTurn($thread, $run, $events);

            return;
        }

        $this->finishTurn($thread, $run, $tracker, $events);
    }

    /**
     * A rejected rate-limit event carries the time the plan's window resets. It
     * arrives before the assistant error that blocks the thread.
     *
     * @param  array<string, mixed>  $line
     */
    private function recordUsageLimitReset(Thread $thread, array $line): void
    {
        $info = $line['rate_limit_info'] ?? null;

        if (($line['type'] ?? null) === 'rate_limit_event' && is_array($info) && ($info['status'] ?? null) === 'rejected' && is_numeric($info['resetsAt'] ?? null)) {
            $thread->forceFill(['blocked_until' => CarbonImmutable::createFromTimestamp((int) $info['resetsAt'])]);
        }
    }

    /**
     * A steer follows its uuid: queued means the runner has it, started means
     * the model took it in (the row is done), cancelled before that sends it
     * again under a new uuid.
     */
    private function applySteeringChange(YakTask $task, TurnTrackerChange $change): void
    {
        $message = PendingSteeringMessage::query()->where('uuid', $change->uuid)->first();

        if ($message === null) {
            return;
        }

        match ($change->state) {
            CommandState::Queued => $message->forceFill(['delivered_at' => $message->delivered_at ?? now()])->save(),
            CommandState::Started => $message->absorb($task),
            CommandState::Cancelled => $message->forceFill(['uuid' => null, 'sent_at' => null, 'delivered_at' => null])->save(),
            default => null,
        };
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

        // Only the transition that ended this turn releases the lock, and only
        // while the lock still belongs to it; a newer turn's lock stays.
        if ($changed === 0 || $thread->lock_turn_id !== $run->id) {
            return;
        }

        $thread->forceFill([
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'lock_acquired_at' => null,
            'runner_state' => RunnerState::Idle,
        ])->save();

        $this->finishedThreads[] = $thread->id;
        $events[] = $this->completionEvent($run->fresh() ?? $run);
    }

    /**
     * A steer whose request-time send failed, or that was switched to steer
     * after the turn began, goes out here.
     */
    private function sendWaitingSteers(): void
    {
        $waiting = PendingSteeringMessage::query()
            ->where('mode', SteeringMode::Steer)
            ->whereNull('sent_at')
            ->whereNull('reviewer_login')
            ->pluck('root_task_id')
            ->unique();

        foreach (YakTask::query()->whereIn('id', $waiting)->whereNotNull('thread_id')->get() as $root) {
            if (Thread::query()->find($root->thread_id)?->runningTurn() !== null) {
                SteeringDelivery::deliverQuietly($root);
            }
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

        $this->runPendingEffects();
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
            'pending_effects' => ['runner' => 'interrupt', 'notice' => 'guard'],
        ])->save();

        unset($this->handlers[$run->id]);
        $thread->forceFill([
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'lock_acquired_at' => null,
            'runner_state' => RunnerState::Idle,
        ])->save();

        $events[] = new TurnFailed($run);
    }

    /**
     * An account failure ends the turn and blocks the thread. Nothing retries
     * and no other account takes over; a person switches the account. A usage
     * limit with no known reset time is a transient API error, not a plan
     * exhaustion: the turn fails and the thread stays unblocked.
     *
     * @param  list<object>  $events
     */
    private function blockTurn(Thread $thread, TaskRun $run, ThreadBlockReason $reason, array &$events): void
    {
        $isTransient = $reason === ThreadBlockReason::UsageLimit && ($thread->blocked_until === null || $thread->blocked_until->isPast());
        $runnerAction = $reason === ThreadBlockReason::InvalidToken ? 'kill' : 'interrupt';

        $run->forceFill([
            'turn_state' => TurnState::Failed,
            'agent_finished_at' => now(),
            'pending_effects' => $isTransient ? ['runner' => 'interrupt'] : ['runner' => $runnerAction, 'notice' => $reason->value],
        ])->save();

        if ($reason === ThreadBlockReason::InvalidToken) {
            ClaudeAccount::query()->whereKey($run->claude_account_id)->update([
                'status' => ClaudeAccountStatus::Invalid->value,
                'last_error' => 'Authentication failed',
            ]);
        }

        unset($this->handlers[$run->id]);
        $thread->forceFill([
            'blocked_reason' => $isTransient ? null : $reason,
            'lock_holder' => ThreadLockHolder::None,
            'lock_turn_id' => null,
            'lock_acquired_at' => null,
            // A rejected credential is never reused: the runner is killed so the next turn starts a fresh one.
            'runner_state' => $reason === ThreadBlockReason::InvalidToken ? RunnerState::None : RunnerState::Idle,
        ])->save();

        $this->finishedThreads[] = $thread->id;
        $events[] = new TurnFailed($run);
    }

    /**
     * Sends the runner command and the notification a turn that ended
     * abnormally still owes. The owed work is stored on the run in the same
     * transaction that ended the turn, so a driver that dies in between
     * finishes it on the next tick.
     */
    private function runPendingEffects(): void
    {
        TaskRun::query()->whereNotNull('thread_id')->whereNotNull('pending_effects')->get()
            ->each(fn (TaskRun $run) => $this->isolated(fn () => $this->runEffectsOf($run)));
    }

    private function runEffectsOf(TaskRun $run): void
    {
        $effects = $run->pending_effects ?? [];
        $thread = Thread::query()->findOrFail($run->thread_id);

        match ($effects['runner'] ?? null) {
            'kill' => $this->commands->kill($thread),
            'interrupt' => $this->commands->interrupt($thread),
            default => null,
        };

        $this->notifyOf($run, $effects['notice'] ?? null);

        $run->forceFill(['pending_effects' => null])->save();
    }

    private function notifyOf(TaskRun $run, ?string $notice): void
    {
        $task = $run->task;

        if ($notice === null || $task === null) {
            return;
        }

        if ($notice === 'guard') {
            SendNotificationJob::dispatch($task, NotificationType::Progress, $this->guard->message($run, (string) $run->guard_reason), personalize: false);

            return;
        }

        $account = $run->claude_account_id === null ? null : ClaudeAccount::query()->find($run->claude_account_id);
        $name = $account->name ?? 'Claude account';
        $isUsageLimit = $notice === ThreadBlockReason::UsageLimit->value;
        $driver = $isUsageLimit && $run->driver_user_id !== null ? User::query()->find($run->driver_user_id) : null;
        $recipients = array_values(collect([$account?->owner, $driver])->filter()->unique('id')->all());

        $message = $isUsageLimit
            ? "The Claude account {$name} hit its usage limit. Switch this thread to another account, or wait for the limit to reset."
            : "Your Claude account {$name} was rejected. Paste a new token in Settings, Claude accounts.";

        SendNotificationJob::dispatch($task, NotificationType::Error, $message, personalize: false, directMessagesOnly: $recipients !== [], directRecipients: $recipients);
    }

    private function pauseFinishedThreads(): void
    {
        $finished = array_unique($this->finishedThreads);
        $this->finishedThreads = [];

        foreach ($finished as $threadId) {
            $thread = Thread::query()->findOrFail($threadId);

            if ($thread->sandbox_state === ThreadSandboxState::Running && $thread->runningTurn() === null) {
                $this->sandbox->syncSessions($thread, (string) $thread->sandbox_container);
            }

            $this->sandbox->pause($thread);
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
            $startsOver = $latest === null || $run?->starts_new_session === true;

            // The init line carries no parent, so a new id after the first one
            // is either the fresh session a /clear starts or the one a turn
            // asked for by starting without a resume id.
            $thread->sessions()->create([
                'session_id' => $sessionId,
                'parent_session_id' => $startsOver ? null : $latest->session_id,
                'kind' => $startsOver ? ThreadSessionKind::Main : ThreadSessionKind::Clear,
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
