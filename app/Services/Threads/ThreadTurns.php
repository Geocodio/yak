<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadStatus;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Exceptions\ThreadActionRefused;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Devices\ShortLockWait;
use App\Services\Stacks\ResearchChildRunner;
use App\Services\TaskAttachmentStager;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Teleport\ThreadOwnership;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entry point for starting, steering and stopping a thread's turns.
 */
class ThreadTurns
{
    public const WAITING_STATES = [TurnState::Pending, TurnState::WaitingForAccount, TurnState::WaitingForSandbox];

    /** The states of a turn that has not ended. */
    public const ACTIVE_STATES = [TurnState::Pending, TurnState::WaitingForAccount, TurnState::WaitingForSandbox, TurnState::Running];

    private const MAX_ADVANCE_FAILURES = 5;

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
            ->whereIn('turn_state', self::ACTIVE_STATES)
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
     * $intent names a request the dashboard made for the person, such as add_preview_manifest.
     * $shownText is what the timeline shows as the person's message when it differs from $prompt.
     * $answersQuestion is set only for a message a person typed, which then answers a question the head task waits on.
     * $storeAttachments saves the message's files before the turn goes out, so dispatchTurn can stage them.
     *
     * @throws ThreadBusy when the thread already runs a turn
     * @throws ThreadNotOwnedByYak when a device owns the thread or a teleport is open
     */
    public function start(YakTask $task, string $prompt, ?User $driver, ?string $model, ?TaskRunKind $kind = null, ?string $shownText = null, ?string $intent = null, ?Closure $storeAttachments = null, ?string $sendKey = null, bool $answersQuestion = false, ?string $effort = null): TaskRun
    {
        [$thread, $run, $account, $blocked] = ShortLockWait::threadTransaction(function () use ($task, $prompt, $driver, $model, $kind, $shownText, $intent, $storeAttachments, $sendKey, $answersQuestion, $effort): array {
            $thread = Thread::query()->lockForUpdate()->findOrFail($task->thread_id);
            app(ThreadOwnership::class)->assertYakMayRunTurns($thread);

            if (! $thread->hasRepository()) {
                throw ThreadActionRefused::because(ThreadActionRefused::NO_REPOSITORY, ThreadActionRefused::NO_REPOSITORY_SENTENCE);
            }

            if ($thread->lock_holder !== ThreadLockHolder::None || self::activeTurn($thread) !== null) {
                throw new ThreadBusy("Thread {$thread->id} already runs a turn.");
            }

            if ($thread->claude_account_id !== null) {
                $account = ClaudeAccount::query()->findOrFail($thread->claude_account_id);
                $thread->model = $model ?? $thread->model;
                $thread->effort = $model !== null || $effort !== null ? $effort : $thread->effort;
            } else {
                $resolved = $this->resolver->forNewThread($driver, $task->repo, null, $model, $effort);
                $account = $resolved->account ?? throw new \RuntimeException("No usable Claude account for thread {$thread->id}.");
                $thread->forceFill(['claude_account_id' => $account->id, 'model' => $resolved->model ?? $model ?? $thread->model, 'effort' => $resolved->effort]);
            }

            if ($driver !== null && $thread->owner_user_id === null) {
                $thread->owner_user_id = $driver->id;
            }

            if ($answersQuestion && $driver !== null) {
                $this->answerOpenQuestion($task, $shownText ?? $prompt, $driver);
            }

            $drained = PendingSteeringMessage::takeableFor($task);
            $queued = $drained->isEmpty() ? null : PendingSteeringMessage::render($drained);
            $text = $queued === null ? $prompt : ($prompt === '' ? $queued : $queued . "\n\n" . $prompt);

            if ($prompt === '' && $shownText === null && $drained->contains(fn (PendingSteeringMessage $message): bool => $message->source !== 'system')) {
                $shownText = '';
            }
            $uuid = (string) Str::uuid();

            if ($this->needsStandingInstructions($thread)) {
                $shownText ??= $text;
                $text = $this->standingInstructionsBlock($thread) . $text;
                $thread->standing_instructions_delivered_at = now();
            }

            $run = TaskRun::query()->create([
                'yak_task_id' => $task->id,
                'kind' => $kind ?? ($thread->runs()->exists() ? TaskRunKind::FollowUp : TaskRunKind::Initial),
                'repo' => $task->repo,
                'source' => $task->source,
                'mode' => $task->mode,
                'thread_id' => $thread->id,
                'claude_account_id' => $account->id,
                'driver_user_id' => $driver?->id,
                'model' => $thread->model,
                'effort' => $thread->effort,
                'prompt_uuid' => $uuid,
                'prompt' => $text,
                'turn_state' => TurnState::Pending,
                'started_at' => now(),
            ]);
            PendingSteeringMessage::drain($task, $drained, $run);
            $message = ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => $uuid, 'kind' => TurnMessageKind::Prompt, 'state' => TurnMessageState::Sent, 'text' => $shownText, 'intent' => $intent, 'send_key' => $sendKey]);

            if ($storeAttachments !== null) {
                $storeAttachments($message);
            }

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
     * A message sent while the head task waits on a question is the answer: the task is no longer waiting
     * on the person, so the sidebar and the question card close. Running is a status no reaper re-dispatches.
     */
    private function answerOpenQuestion(YakTask $task, string $reply, User $driver): void
    {
        $locked = YakTask::query()->whereKey($task->id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== TaskStatus::AwaitingClarification) {
            return;
        }

        $locked->recordClarificationAnswers([], $reply, $driver->name);
        $locked->markClarificationAnswersConsumed();
        $locked->update([
            'status' => TaskStatus::Running,
            'clarification_expires_at' => null,
            'clarification_reminder_at' => null,
        ]);
        $task->refresh();
    }

    /**
     * A resumed session never sees the system prompt Yak appends, so a thread whose session started on a
     * person's computer carries the standing instructions in its first Yak prompt, once.
     */
    private function needsStandingInstructions(Thread $thread): bool
    {
        return $thread->standing_instructions_delivered_at === null
            && YakTask::query()->where('thread_id', $thread->id)->oldest('id')->value('source') === YakTask::SOURCE_TELEPORT;
    }

    private function standingInstructionsBlock(Thread $thread): string
    {
        return "<yak-standing-instructions>\nThese are Yak's standing instructions for this thread. They take precedence over earlier instructions from the computer session.\n\n"
            . YakPromptBuilder::refreshThreadSystemPrompt($thread)
            . "\n</yak-standing-instructions>\n\n";
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
                $thread->fresh_session_pending ? null : $thread->activeSession()?->session_id,
                YakPromptBuilder::refreshThreadSystemPrompt($thread),
                $thread->sharesParentSandbox() ? ResearchChildRunner::worktreePath($thread) : null,
            );
            $thread->forceFill(['fresh_session_pending' => false])->save();
        } elseif ($thread->model !== null && $thread->model !== $thread->runner_model) {
            $this->commands->setModel($thread, $thread->model);
        }

        if ($thread->runner_state !== RunnerState::None && $thread->effort !== $thread->runner_effort) {
            $this->commands->setEffort($thread, $thread->effort);
        }

        // Claude pushes during the turn, so the sandbox needs a token that outlives it.
        app(ThreadGitHubCredentials::class)->ensureFresh($thread);

        // The turn is recorded as running before the command goes out: the broker can answer no_runner within
        // milliseconds, and the driver only recovers a lost runner for a turn that is already running.
        $waitingState = $run->getAttribute('turn_state');
        self::trackUuid($run, (string) $run->prompt_uuid);
        $run->forceFill(['turn_state' => TurnState::Running])->save();

        try {
            $this->commands->turn($thread, (string) $run->prompt_uuid, $this->promptWithAttachments($thread, $run));
        } catch (Throwable $exception) {
            $run->forceFill(['turn_state' => $waitingState])->save();

            throw $exception;
        }

        if (in_array($thread->blocked_reason, [ThreadBlockReason::WaitingForAccount, ThreadBlockReason::WaitingForSandbox], true)) {
            $thread->forceFill(['blocked_reason' => null, 'blocked_at' => null])->save();
        }
    }

    /**
     * The run's prompt followed by the files its message carried, pushed into the sandbox.
     */
    private function promptWithAttachments(Thread $thread, TaskRun $run): string
    {
        $attachments = ThreadTurnMessage::query()->where('uuid', $run->prompt_uuid)->first()?->attachments->all() ?? [];
        $prompt = (string) $run->prompt;

        if ($attachments === []) {
            return $prompt;
        }

        $staged = (new TaskAttachmentStager(ThreadShell::for($thread)))->stage((string) $thread->sandbox_container, $attachments);

        return $staged === '' ? $prompt : rtrim($prompt) . "\n\n" . $staged;
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
                    $thread = Thread::query()->withoutGlobalScopes()->find($waiting->thread_id);

                    if ($thread === null) {
                        $this->failWaiter($waiting);

                        return;
                    }

                    if ($thread->status !== ThreadStatus::Open) {
                        return;
                    }

                    $failureKey = "thread-turns:advance-failures:{$waiting->id}";

                    try {
                        DB::transaction(function () use ($waiting): void {
                            $thread = Thread::query()->withoutGlobalScopes()->lockForUpdate()->find($waiting->thread_id);
                            $run = TaskRun::query()->find($waiting->id);

                            if ($thread !== null && $run !== null && $run->getAttribute('turn_state') === $waiting->getAttribute('turn_state')) {
                                $this->advance($thread, $run, ClaudeAccount::query()->findOrFail($run->claude_account_id));
                            }
                        });

                        Cache::forget($failureKey);
                    } catch (Throwable $exception) {
                        report($exception);

                        Cache::add($failureKey, 0, now()->addDay());

                        if ($exception instanceof ModelNotFoundException || Cache::increment($failureKey) >= self::MAX_ADVANCE_FAILURES) {
                            $this->failWaiter($waiting);
                            Cache::forget($failureKey);
                        }
                    }
                });
        }
    }

    /**
     * Ends a waiting turn that can never advance as Failed, through the same
     * path as every other turn end so the thread lock and the slot are freed.
     */
    public function failWaiter(TaskRun $run): void
    {
        $finisher = app(TurnFinisher::class);
        $events = [];

        DB::transaction(function () use ($run, $finisher, &$events): void {
            $thread = Thread::query()->withoutGlobalScopes()->lockForUpdate()->find($run->thread_id);

            if ($thread === null) {
                $changed = TaskRun::query()->whereKey($run->id)->whereIn('turn_state', self::WAITING_STATES)
                    ->update(['turn_state' => TurnState::Failed, 'finished_at' => now()]);

                if ($changed === 1) {
                    $this->concurrency->releaseRun($run);
                    $this->cancelMessages($run);
                }

                return;
            }

            if (! $finisher->finish($thread, $run, TurnState::Failed, $events, self::WAITING_STATES)) {
                return;
            }

            if (in_array($thread->blocked_reason, [ThreadBlockReason::WaitingForAccount, ThreadBlockReason::WaitingForSandbox], true)) {
                $thread->forceFill(['blocked_reason' => null, 'blocked_at' => null]);
            }

            $this->cancelMessages($run);
            $thread->save();
        });

        $finisher->dispatch($events);
    }

    private function cancelMessages(TaskRun $run): void
    {
        ThreadTurnMessage::query()
            ->where('task_run_id', $run->id)
            ->whereIn('state', [TurnMessageState::Sent, TurnMessageState::Queued, TurnMessageState::Started])
            ->update(['state' => TurnMessageState::Cancelled]);
    }

    /**
     * Records a steering message and sends it when a turn is running.
     * Otherwise it stays queued for the next prompt. `$source` is `thread` for
     * a person's message, which reaches the runner wrapped; any other source
     * carries text Yak already framed. `$storeAttachments` runs before delivery
     * so the files exist when the text is built. With `$isQueueOnly` the message
     * is never handed to the running turn; it waits for the next prompt.
     *
     * Delivery is at least once: when the command reached the runner but its
     * answer was lost, or the bookkeeping after it failed, the message is queued
     * and Claude reads it again in the next prompt.
     */
    public function steer(Thread $thread, string $text, ?User $sender, ?string $model, ?Closure $storeAttachments = null, string $source = 'system', ?string $sendKey = null, bool $isQueueOnly = false, ?string $effort = null): PendingSteeringMessage
    {
        app(ThreadOwnership::class)->assertYakMayRunTurns($thread->fresh() ?? $thread);

        $root = $thread->tasks()->oldest()->firstOrFail();

        $message = PendingSteeringMessage::query()->create([
            'root_task_id' => $root->rootTask()->id,
            'text' => $text,
            'source' => $source,
            'mode' => $isQueueOnly ? SteeringMode::Queue : SteeringMode::Steer,
            'author_name' => $sender?->name,
            'author_user_id' => $source === 'thread' ? $sender?->id : null,
            'uuid' => (string) Str::uuid(),
            'model' => $model,
            'effort' => $effort,
            'send_key' => $sendKey,
        ]);

        if ($storeAttachments !== null) {
            $storeAttachments($message);
        }

        if ($sender !== null && $thread->owner_user_id === null) {
            Thread::query()->whereKey($thread->id)->whereNull('owner_user_id')->update(['owner_user_id' => $sender->id]);
        }

        // Only a person's thread message starts a turn on its own; every other source keeps waiting for the next prompt.
        $isPersonMessage = $source === 'thread' && $sender !== null;

        if ($isQueueOnly) {
            if ($isPersonMessage) {
                $this->startQueuedAfterCompletedTurn($thread);
            }

            return $message;
        }

        try {
            $isDelivered = app(SteeringDelivery::class)->deliver($thread, $message);
        } catch (Throwable $exception) {
            // The row stays as one queued message so a retry with the same send key replays this result.
            report($exception);
            $isDelivered = false;
        }

        if (! $isDelivered) {
            $message->forceFill(['mode' => SteeringMode::Queue])->save();

            if ($isPersonMessage) {
                $this->startQueuedAfterCompletedTurn($thread);

                // The turn that started drained this message into its prompt, so it was sent, not queued.
                if (! PendingSteeringMessage::query()->whereKey($message->id)->exists()) {
                    $message->setAttribute('mode', SteeringMode::Steer);
                }
            }
        }

        return $message;
    }

    /**
     * A message queued as the turn ended has no later trigger, so a thread that is idle after a completed turn starts it now.
     * The runner drains under its own lock, so a message is never sent twice.
     */
    private function startQueuedAfterCompletedTurn(Thread $thread): void
    {
        if (self::activeTurn($thread) !== null) {
            return;
        }

        $latest = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();

        if ($latest !== null) {
            app(QueuedMessageRunner::class)->afterTurn($latest);
        }
    }

    /**
     * Interrupts a running turn or cancels a waiting one. Does nothing when
     * the thread is idle.
     */
    public function stop(Thread $thread): void
    {
        $finisher = app(TurnFinisher::class);
        $events = [];

        ShortLockWait::threadTransaction(function () use ($thread, $finisher, &$events): void {
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

            $this->cancelMessages($run);

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
            // A sandbox that cannot be cloned until its repository template is rebuilt is a repository wait.
            $reason = $thread->blocked_reason === ThreadBlockReason::WaitingForRepository ? ThreadBlockReason::WaitingForRepository : ThreadBlockReason::WaitingForSandbox;
            $this->wait($thread, $run, TurnState::WaitingForSandbox, $reason);

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
