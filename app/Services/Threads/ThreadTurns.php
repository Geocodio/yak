<?php

namespace App\Services\Threads;

use App\Enums\ClaudeAccountStatus;
use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Exceptions\NoTurnToContinue;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts one turn on a thread: records the turn, takes the thread lock and
 * writes the commands that make the runner work on it.
 */
class ThreadTurns
{
    public function __construct(private readonly ThreadCommands $commands) {}

    /**
     * Returns null, with no runner command sent, when the thread is blocked or
     * its account cannot be used; the thread's blocked_reason says why. A
     * thread never moves to another account on its own.
     *
     * With $startsNewSession the runner is restarted without a resume id and
     * the turn's first session becomes a new main session of the thread.
     *
     * With $continuesStoppedTurn the thread must have no running turn, a free
     * lock and a latest turn that the runaway guard stopped; that check runs
     * under the same row lock that takes the thread lock, so two requests
     * cannot both continue. Otherwise NoTurnToContinue is thrown.
     *
     * @throws NoTurnToContinue
     */
    public function start(YakTask $task, TaskRunKind $kind, string $prompt, string $systemPrompt, ?int $driverUserId, string $containerName, bool $continuesStoppedTurn = false, bool $startsNewSession = false): ?TaskRun
    {
        $promptUuid = (string) Str::uuid();

        $started = DB::transaction(function () use ($task, $kind, $prompt, $driverUserId, $containerName, $promptUuid, $continuesStoppedTurn, $startsNewSession): ?array {
            $thread = Thread::query()->lockForUpdate()->findOrFail($task->thread_id);

            if ($continuesStoppedTurn && ! $this->canContinue($thread)) {
                throw new NoTurnToContinue;
            }

            $account = ClaudeAccount::query()->find($thread->claude_account_id);
            $blockedReason = $this->blockedReason($thread, $account);

            if ($blockedReason !== null || $account === null) {
                $thread->forceFill(['blocked_reason' => $blockedReason])->save();

                return null;
            }

            if (in_array($thread->blocked_reason, [ThreadBlockReason::InvalidToken, ThreadBlockReason::NoValidAccount], true)) {
                $thread->forceFill(['blocked_reason' => null]);
            }

            $account->forceFill(['last_used_at' => now()])->save();

            $run = TaskRun::query()->create([
                'yak_task_id' => $task->id,
                'kind' => $kind,
                'repo' => $task->repo,
                'source' => $task->source,
                'mode' => $task->mode,
                'started_at' => now(),
                'thread_id' => $thread->id,
                'claude_account_id' => $account->id,
                'model' => $thread->model,
                'driver_user_id' => $driverUserId,
                'turn_state' => TurnState::Queued,
                'prompt_uuid' => $promptUuid,
                'command_states' => [$promptUuid => 'sent'],
                'prompt' => $prompt,
                'starts_new_session' => $startsNewSession,
            ]);

            $thread->forceFill([
                'sandbox_container' => $containerName,
                'lock_holder' => ThreadLockHolder::Sandbox,
                'lock_turn_id' => $run->id,
                'lock_acquired_at' => now(),
                'owner_user_id' => $thread->owner_user_id ?? $driverUserId,
            ])->save();

            return [$thread, $run, $account];
        });

        if ($started === null) {
            return null;
        }

        [$thread, $run, $account] = $started;

        if ($startsNewSession) {
            // The runner starts without a resume id, so the first init line opens a new session.
            if ($thread->runner_state !== RunnerState::None) {
                $this->commands->kill($thread);
            }

            $this->commands->startRunner($thread, $account, null, $systemPrompt);
        } elseif ($thread->runner_state === RunnerState::None) {
            $this->commands->startRunner($thread, $account, $thread->activeSession()?->session_id, $systemPrompt);
        } elseif ($thread->runner_claude_account_id !== null && $thread->runner_claude_account_id !== $account->id) {
            // The runner holds the old account's credential, so a switch restarts it on the same session.
            $this->commands->kill($thread);
            $this->commands->startRunner($thread, $account, $thread->activeSession()?->session_id, $systemPrompt);
        } elseif ($thread->model !== null && $thread->model !== $thread->runner_model) {
            $this->commands->setModel($thread, $thread->model);
        }

        $this->commands->turn($thread, $promptUuid, $prompt);

        $run->forceFill(['turn_state' => TurnState::Running])->save();

        return $run;
    }

    private function canContinue(Thread $thread): bool
    {
        return $thread->runningTurn() === null
            && $thread->lock_holder === ThreadLockHolder::None
            && $thread->turns()->latest('id')->first()?->turn_state === TurnState::StoppedByGuard;
    }

    /**
     * A usage limit stays until someone switches the account or it clears. A
     * missing or rejected account blocks for as long as it is unusable, so a
     * re-validated token unblocks its threads by itself.
     */
    private function blockedReason(Thread $thread, ?ClaudeAccount $account): ?ThreadBlockReason
    {
        return match (true) {
            $account === null => ThreadBlockReason::NoValidAccount,
            $account->status === ClaudeAccountStatus::Invalid => ThreadBlockReason::InvalidToken,
            ! $account->isUsable() => ThreadBlockReason::NoValidAccount,
            $thread->blocked_reason === ThreadBlockReason::UsageLimit => ThreadBlockReason::UsageLimit,
            default => null,
        };
    }
}
