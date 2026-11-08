<?php

namespace App\Services\Threads;

use App\Enums\CommandState;
use App\Enums\RunnerState;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings a thread's turn back after its runner was lost: a fresh runner
 * resumes the session, unabsorbed steers go out again with their original
 * uuids, and the prompt is either re-sent or replaced by a continuation.
 */
class RunnerRecovery
{
    public const CONTINUATION_TEXT = 'Yak restarted while you were working. Continue the task from where you left off.';

    public function __construct(private readonly ThreadCommands $commands) {}

    public function recover(Thread $thread): void
    {
        $run = $thread->runningTurn();

        if ($run === null) {
            $thread->forceFill(['runner_state' => RunnerState::None])->save();

            return;
        }

        $account = ClaudeAccount::query()->find($thread->claude_account_id)
            ?? throw new RuntimeException("Thread {$thread->id} has no Claude account to restart a runner with.");

        $this->commands->kill($thread);
        $this->commands->startRunner($thread, $account, $thread->activeSession()?->session_id, (string) $thread->append_system_prompt);

        $states = $run->command_states?->map(fn (CommandState $state): string => $state->value)->all() ?? [];
        $promptUuid = (string) $run->prompt_uuid;
        $promptStarted = $this->hasStarted($states[$promptUuid] ?? null);

        $steers = PendingSteeringMessage::query()
            ->where('task_run_id', $run->id)
            ->whereNotNull('uuid')
            ->whereNull('absorbed_at')
            ->orderBy('id')
            ->get();

        foreach ($steers as $steer) {
            $steerUuid = (string) $steer->uuid;

            if (! $this->hasStarted($states[$steerUuid] ?? null)) {
                $this->commands->steer($thread, $steerUuid, $steer->text, $steer->priority);
            }
        }

        // The old runner will never finish commands it had started, so they
        // are closed here; the continuation or the re-sent commands carry on.
        foreach ($states as $uuid => $state) {
            if ($state === CommandState::Started->value) {
                $states[$uuid] = CommandState::Cancelled->value;
            }
        }

        if ($promptStarted) {
            $uuid = (string) Str::uuid();
            $states[$uuid] = CommandState::Sent->value;
            $this->commands->turn($thread, $uuid, self::CONTINUATION_TEXT);
        } else {
            $this->commands->turn($thread, $promptUuid, (string) $run->prompt);
        }

        $this->save($run, $states);
    }

    /**
     * @param  array<string, string>  $states
     */
    private function save(TaskRun $run, array $states): void
    {
        $run->forceFill(['command_states' => $states, 'restarted' => true])->save();
    }

    private function hasStarted(?string $state): bool
    {
        return in_array($state, [CommandState::Started->value, CommandState::Completed->value, CommandState::Cancelled->value], true);
    }
}
