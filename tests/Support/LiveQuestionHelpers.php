<?php

use App\Enums\RunnerState;
use App\Enums\TaskStatus;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;

/**
 * @return array{0: Thread, 1: TaskRun, 2: YakTask, 3: User}
 */
function threadWithRunningTurn(): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $account = ClaudeAccount::factory()->create();
    $thread = Thread::factory()->create(['owner_user_id' => $user->id, 'created_by_user_id' => $user->id, 'claude_account_id' => $account->id]);
    $thread->forceFill(['runner_generation' => 1, 'runner_state' => RunnerState::Busy, 'lock_holder' => ThreadLockHolder::Sandbox, 'lock_turn_id' => 'turn-1'])->save();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running, 'started_by_user_id' => $user->id]);
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'claude_account_id' => $account->id,
        'turn_state' => TurnState::Running,
        'driver_user_id' => $user->id,
        'started_at' => now(),
        'agent_started_at' => null,
        'finished_at' => null,
    ]);

    return [$thread->refresh(), $run, $task, $user];
}

function questionLineFor(Thread $thread, array $questions, int $sequence = 1, string $id = 'rq-1'): void
{
    $line = ['type' => 'runner', 'event' => 'question', 'id' => $id, 'toolUseId' => 'toolu_1', 'questions' => $questions];
    app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, $sequence, $line));
}

function colourQuestion(): array
{
    return [
        'question' => 'Which screens first?',
        'header' => 'Screens',
        'options' => [['label' => 'Contacts', 'description' => 'List and detail'], ['label' => 'Deals', 'description' => '']],
        'multiSelect' => true,
    ];
}

function widthQuestion(): array
{
    return [
        'question' => 'Which width?',
        'header' => 'Width',
        'options' => [['label' => 'Desktop', 'description' => ''], ['label' => 'Laptop', 'description' => '']],
        'multiSelect' => false,
    ];
}
