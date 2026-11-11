<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Brings a thread's running turn back after its runner was lost: the runner is
 * restarted with the session resumed, messages that never started are re-sent
 * with their original uuids, and the turn's recorded cost is kept. Restarts
 * are capped per turn and back off exponentially.
 */
class RunnerRecovery
{
    public const CONTINUATION_TEXT = 'Yak restarted while you were working. Continue the task from where you left off.';

    public function __construct(
        private readonly ThreadCommands $commands,
        private readonly TurnFinisher $finisher,
    ) {}

    /**
     * The caller holds the thread row lock and saves the thread afterwards.
     * Does nothing when no turn is running. Within the backoff the restart is
     * left for recoverDue().
     *
     * @param  list<object>  $events
     */
    public function recover(Thread $thread, array &$events = []): void
    {
        $run = ThreadTurns::runningTurn($thread);

        if ($run === null) {
            return;
        }

        $thread->forceFill(['runner_state' => RunnerState::None]);

        if ((int) $run->getAttribute('restart_count') >= (int) config('yak.threads.runner_restart_cap')) {
            $this->finisher->finish($thread, $run, TurnState::Failed, $events);
            $thread->forceFill(['runner_state' => RunnerState::None]);

            return;
        }

        $dueAt = $thread->getAttribute('runner_restart_at');

        if ($dueAt !== null && CarbonImmutable::parse($dueAt)->isFuture()) {
            return;
        }

        $account = $run->claudeAccount ?? $thread->claudeAccount;

        if ($account === null) {
            return;
        }

        $messages = ThreadTurnMessage::query()->where('task_run_id', $run->id)->with('pendingSteeringMessage')->get();
        $promptUuid = (string) $run->getAttribute('prompt_uuid');
        $prompt = $messages->first(fn (ThreadTurnMessage $message): bool => $message->uuid === $promptUuid);
        $promptNeverStarted = $prompt !== null && in_array($prompt->state, [TurnMessageState::Sent, TurnMessageState::Queued], true);

        $this->commands->kill($thread);
        $this->commands->startRunner($thread, $account, $thread->activeSession()?->session_id, (string) $thread->append_system_prompt);

        $messages
            ->filter(fn (ThreadTurnMessage $message): bool => $message->state === TurnMessageState::Started)
            ->each(fn (ThreadTurnMessage $message): bool => $message->forceFill(['state' => TurnMessageState::Cancelled])->save());

        $messages
            ->filter(fn (ThreadTurnMessage $message): bool => $message->kind === TurnMessageKind::Steer && in_array($message->state, [TurnMessageState::Sent, TurnMessageState::Queued], true))
            ->filter(fn (ThreadTurnMessage $message): bool => $message->pendingSteeringMessage !== null)
            ->each(fn (ThreadTurnMessage $message) => $this->commands->steer($thread, $message->uuid, SteeringText::for($thread, $message->pendingSteeringMessage)));

        ThreadTurns::abandonStarted($run);

        if ($promptNeverStarted) {
            $this->commands->turn($thread, $promptUuid, (string) $run->getAttribute('prompt'));
        } else {
            $uuid = (string) Str::uuid();

            $this->commands->turn($thread, $uuid, self::CONTINUATION_TEXT);
            ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => $uuid, 'kind' => TurnMessageKind::Prompt, 'state' => TurnMessageState::Sent]);
            ThreadTurns::trackUuid($run, $uuid);
        }

        $restartCount = (int) $run->getAttribute('restart_count') + 1;
        $run->forceFill(['restart_count' => $restartCount])->save();
        $thread->forceFill(['runner_restart_at' => now()->addSeconds(2 ** $restartCount)]);
    }

    /**
     * Restarts runners whose recovery waited for its backoff.
     */
    public function recoverDue(): void
    {
        Thread::query()
            ->where('runner_state', RunnerState::None->value)
            ->whereNotNull('runner_restart_at')
            ->where('runner_restart_at', '<=', now())
            ->pluck('id')
            ->each(function (int $threadId): void {
                try {
                    $events = [];

                    DB::transaction(function () use ($threadId, &$events): void {
                        $thread = Thread::query()->lockForUpdate()->find($threadId);

                        if ($thread === null || $thread->runner_state !== RunnerState::None) {
                            return;
                        }

                        $this->recover($thread, $events);
                        $thread->save();
                    });

                    $this->finisher->dispatch($events);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
