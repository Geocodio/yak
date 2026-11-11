<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\TaskRunKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Stacks\ResearchChildRunner;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Teleport\ThreadOwnership;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entry point for starting, steering and stopping a thread's turns.
 */
class ThreadTurns
{
    private const WAITING_STATES = [TurnState::Pending, TurnState::WaitingForAccount, TurnState::WaitingForSandbox];

    public function __construct(
        private readonly ThreadCommands $commands,
        private readonly ClaudeAccountResolver $resolver,
        private readonly AccountConcurrency $concurrency,
        private readonly ThreadSandbox $sandbox,
    ) {}

    /**
     * The thread's latest turn that is running on the runner.
     */
    public static function runningTurn(Thread $thread): ?TaskRun
    {
        return TaskRun::query()
            ->where('thread_id', $thread->id)
            ->where('turn_state', TurnState::Running)
            ->latest('id')
            ->first();
    }

    /**
     * The thread's latest turn that has not ended, including turns still
     * waiting for an account or a sandbox.
     */
    public static function activeTurn(Thread $thread): ?TaskRun
    {
        return TaskRun::query()
            ->where('thread_id', $thread->id)
            ->whereIn('turn_state', [...self::WAITING_STATES, TurnState::Running])
            ->latest('id')
            ->first();
    }

    /**
     * The turn's tracker, restored from its stored state.
     */
    public static function trackerFor(TaskRun $run): TurnTracker
    {
        $stored = $run->getAttribute('tracker_state');
        $state = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($state) && $state !== []
            ? TurnTracker::fromState($state)
            : app()->make(TurnTracker::class, ['graceSeconds' => (int) config('yak.threads.turn_grace_seconds')]);
    }

    /**
     * Registers a uuid sent to the runner with the turn's tracker.
     */
    public static function trackUuid(TaskRun $run, string $uuid): void
    {
        $tracker = self::trackerFor($run);
        $tracker->track($uuid);

        $run->forceFill(['tracker_state' => json_encode($tracker->state(), JSON_THROW_ON_ERROR)])->save();
    }

    /**
     * Drops the uuids that started on a runner generation that is gone, so the
     * continuation sent to the new runner can end the turn.
     */
    public static function abandonStarted(TaskRun $run): void
    {
        $tracker = self::trackerFor($run);
        $tracker->abandonStarted(CarbonImmutable::now());

        $run->forceFill(['tracker_state' => json_encode($tracker->state(), JSON_THROW_ON_ERROR)])->save();
    }

    /**
     * A thread that is blocked on its account keeps the new turn pending and
     * the caller reads blocked_reason; a thread never moves to another account.
     *
     * @throws ThreadBusy when the thread already runs a turn
     * @throws ThreadNotOwnedByYak when a device owns the thread or a teleport is open
     */
    public function start(YakTask $task, string $prompt, ?User $driver, ?string $model): TaskRun
    {
        [$thread, $run, $account, $blocked] = DB::transaction(function () use ($task, $prompt, $driver, $model): array {
            $thread = Thread::query()->lockForUpdate()->findOrFail($task->thread_id);
            app(ThreadOwnership::class)->assertYakMayRunTurns($thread);

            if ($thread->lock_holder !== ThreadLockHolder::None || self::activeTurn($thread) !== null) {
                throw new ThreadBusy("Thread {$thread->id} already runs a turn.");
            }

            if ($thread->claude_account_id !== null) {
                $account = ClaudeAccount::query()->findOrFail($thread->claude_account_id);
                $thread->model = $model ?? $thread->model;
            } else {
                $resolved = $this->resolver->forNewThread($driver, $task->repo, null, $model);
                $account = $resolved->account ?? throw new \RuntimeException("No usable Claude account for thread {$thread->id}.");
                $thread->forceFill(['claude_account_id' => $account->id, 'model' => $resolved->model ?? $model ?? $thread->model]);
            }

            $queued = PendingSteeringMessage::drainFor($task);
            $text = $queued === null ? $prompt : $queued . "\n\n" . $prompt;
            $uuid = (string) Str::uuid();

            $run = TaskRun::query()->create([
                'yak_task_id' => $task->id,
                'kind' => $thread->runs()->exists() ? TaskRunKind::FollowUp : TaskRunKind::Initial,
                'repo' => $task->repo,
                'source' => $task->source,
                'mode' => $task->mode,
                'thread_id' => $thread->id,
                'claude_account_id' => $account->id,
                'driver_user_id' => $driver?->id,
                'model' => $thread->model,
                'prompt_uuid' => $uuid,
                'prompt' => $text,
                'turn_state' => TurnState::Pending,
                'started_at' => now(),
            ]);
            ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => $uuid, 'kind' => TurnMessageKind::Prompt, 'state' => TurnMessageState::Sent]);

            $thread->forceFill([
                'lock_holder' => ThreadLockHolder::Sandbox,
                'lock_turn_id' => (string) $run->id,
                'lock_acquired_at' => now(),
            ])->save();

            $blocked = in_array($thread->blocked_reason, [ThreadBlockReason::AccountInvalid, ThreadBlockReason::AccountExpired, ThreadBlockReason::UsageLimit], true);

            return [$thread, $run, $account, $blocked];
        });

        if (! $blocked) {
            $this->advance($thread, $run, $account);
        }

        return $run->refresh();
    }

    /**
     * Sends the turn to the runner and marks it running.
     */
    public function dispatchTurn(Thread $thread, TaskRun $run): void
    {
        $thread->refresh();

        if ($run->refresh()->getAttribute('turn_state') === TurnState::Running || ! in_array($run->getAttribute('turn_state'), self::WAITING_STATES, true)) {
            return;
        }

        if ($thread->runner_state === RunnerState::None) {
            $account = ClaudeAccount::query()->findOrFail($run->claude_account_id);
            $this->commands->startRunner(
                $thread,
                $account,
                $thread->activeSession()?->session_id,
                (string) $thread->append_system_prompt,
                $thread->sharesParentSandbox() ? ResearchChildRunner::worktreePath($thread) : null,
            );
        } elseif ($thread->model !== null && $thread->model !== $thread->runner_model) {
            $this->commands->setModel($thread, $thread->model);
        }

        $this->commands->turn($thread, (string) $run->prompt_uuid, (string) $run->prompt);
        self::trackUuid($run, (string) $run->prompt_uuid);

        $run->forceFill(['turn_state' => TurnState::Running])->save();

        if (in_array($thread->blocked_reason, [ThreadBlockReason::WaitingForAccount, ThreadBlockReason::WaitingForSandbox], true)) {
            $thread->forceFill(['blocked_reason' => null, 'blocked_at' => null])->save();
        }
    }

    /**
     * Retries the account slot and sandbox wake for turns that could not start,
     * oldest first. A pending turn counts once its thread is unblocked and
     * start() has had ten seconds to advance it. A failing turn does not hold
     * up the others.
     */
    public function resumeWaiting(): void
    {
        foreach ([TurnState::Pending, TurnState::WaitingForAccount, TurnState::WaitingForSandbox] as $state) {
            TaskRun::query()
                ->whereNotNull('thread_id')
                ->where('turn_state', $state)
                ->when($state === TurnState::Pending, fn ($query) => $query
                    ->where('started_at', '<=', now()->subSeconds(10))
                    ->whereHas('thread', fn ($thread) => $thread->whereNull('blocked_reason')))
                ->oldest('id')
                ->get()
                ->each(function (TaskRun $waiting): void {
                    try {
                        DB::transaction(function () use ($waiting): void {
                            $thread = Thread::query()->lockForUpdate()->find($waiting->thread_id);
                            $run = TaskRun::query()->find($waiting->id);

                            if ($thread !== null && $run !== null && $run->getAttribute('turn_state') === $waiting->getAttribute('turn_state')) {
                                $this->advance($thread, $run, ClaudeAccount::query()->findOrFail($run->claude_account_id));
                            }
                        });
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
        }
    }

    /**
     * Records a steering message and sends it when a turn is running.
     * Otherwise it stays queued for the next prompt. `$source` is `thread` for
     * a person's message, which reaches the runner wrapped; any other source
     * carries text Yak already framed. `$storeAttachments` runs before delivery
     * so the files exist when the text is built.
     */
    public function steer(Thread $thread, string $text, ?User $sender, ?string $model, ?Closure $storeAttachments = null, string $source = 'system'): PendingSteeringMessage
    {
        app(ThreadOwnership::class)->assertYakMayRunTurns($thread->fresh() ?? $thread);

        $root = $thread->tasks()->oldest()->firstOrFail();

        $message = PendingSteeringMessage::query()->create([
            'root_task_id' => $root->rootTask()->id,
            'text' => $text,
            'source' => $source,
            'mode' => SteeringMode::Steer,
            'author_name' => $sender?->name,
            'uuid' => (string) Str::uuid(),
            'model' => $model,
        ]);

        if ($storeAttachments !== null) {
            $storeAttachments($message);
        }

        if (! app(SteeringDelivery::class)->deliver($thread, $message)) {
            $message->forceFill(['mode' => SteeringMode::Queue])->save();
        }

        return $message;
    }

    /**
     * Interrupts a running turn or cancels a waiting one. Does nothing when
     * the thread is idle.
     */
    public function stop(Thread $thread): void
    {
        $finisher = app(TurnFinisher::class);
        $events = [];

        DB::transaction(function () use ($thread, $finisher, &$events): void {
            $locked = Thread::query()->lockForUpdate()->findOrFail($thread->id);
            $run = self::activeTurn($locked);

            if ($run === null) {
                return;
            }

            $wasRunning = $run->getAttribute('turn_state') === TurnState::Running;

            if ($wasRunning) {
                $this->commands->interrupt($locked);
            }

            if (! $finisher->finish($locked, $run, TurnState::Interrupted, $events, $wasRunning ? [TurnState::Running] : self::WAITING_STATES)) {
                return;
            }

            if (in_array($locked->blocked_reason, [ThreadBlockReason::WaitingForAccount, ThreadBlockReason::WaitingForSandbox], true)) {
                $locked->forceFill(['blocked_reason' => null, 'blocked_at' => null]);
            }

            ThreadTurnMessage::query()
                ->where('task_run_id', $run->id)
                ->whereIn('state', [TurnMessageState::Sent, TurnMessageState::Queued, TurnMessageState::Started])
                ->update(['state' => TurnMessageState::Cancelled]);

            $locked->save();
        });

        $finisher->dispatch($events);
    }

    /**
     * Takes the account slot, wakes the sandbox and sends the turn, or records
     * what the turn is waiting for.
     */
    private function advance(Thread $thread, TaskRun $run, ClaudeAccount $account): void
    {
        if ($run->getAttribute('turn_state') !== TurnState::WaitingForSandbox && ! $this->concurrency->acquire($account, $run)) {
            $this->wait($thread, $run, TurnState::WaitingForAccount, ThreadBlockReason::WaitingForAccount);

            return;
        }

        $wake = $this->sandbox->ensureAwake($thread);

        if ($wake->waitingForSlot || $wake->restoring || ! $wake->ready) {
            $this->wait($thread, $run, TurnState::WaitingForSandbox, ThreadBlockReason::WaitingForSandbox);

            return;
        }

        $this->dispatchTurn($thread, $run);
    }

    private function wait(Thread $thread, TaskRun $run, TurnState $state, ThreadBlockReason $reason): void
    {
        $run->forceFill(['turn_state' => $state])->save();
        $thread->forceFill(['blocked_reason' => $reason, 'blocked_at' => $thread->blocked_reason === $reason ? $thread->blocked_at : now()])->save();
    }
}
