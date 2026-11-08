<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Starts one turn on a thread: records the turn, takes the thread lock and
 * writes the commands that make the runner work on it.
 */
class ThreadTurns
{
    public function __construct(private readonly ThreadCommands $commands) {}

    public function start(YakTask $task, TaskRunKind $kind, string $prompt, string $systemPrompt, ?int $driverUserId, string $containerName): TaskRun
    {
        $promptUuid = (string) Str::uuid();

        [$thread, $run] = DB::transaction(function () use ($task, $kind, $prompt, $driverUserId, $containerName, $promptUuid): array {
            $thread = Thread::query()->lockForUpdate()->findOrFail($task->thread_id);

            $run = TaskRun::query()->create([
                'yak_task_id' => $task->id,
                'kind' => $kind,
                'repo' => $task->repo,
                'source' => $task->source,
                'mode' => $task->mode,
                'started_at' => now(),
                'thread_id' => $thread->id,
                'claude_account_id' => $thread->claude_account_id,
                'model' => $thread->model,
                'driver_user_id' => $driverUserId,
                'turn_state' => TurnState::Queued,
                'prompt_uuid' => $promptUuid,
                'command_states' => [$promptUuid => 'sent'],
                'prompt' => $prompt,
            ]);

            $thread->forceFill([
                'sandbox_container' => $containerName,
                'lock_holder' => ThreadLockHolder::Sandbox,
                'lock_turn_id' => $run->id,
                'lock_acquired_at' => now(),
                'owner_user_id' => $thread->owner_user_id ?? $driverUserId,
            ])->save();

            return [$thread, $run];
        });

        if ($thread->runner_state === RunnerState::None) {
            $this->commands->startRunner($thread, $this->account($thread), $thread->activeSession()?->session_id, $systemPrompt);
        } elseif ($thread->model !== null && $thread->model !== $thread->runner_model) {
            $this->commands->setModel($thread, $thread->model);
        }

        $this->commands->turn($thread, $promptUuid, $prompt);

        $run->forceFill(['turn_state' => TurnState::Running])->save();

        return $run;
    }

    private function account(Thread $thread): ClaudeAccount
    {
        return ClaudeAccount::query()->find($thread->claude_account_id)
            ?? throw new RuntimeException("Thread {$thread->id} has no Claude account to start a runner with.");
    }
}
