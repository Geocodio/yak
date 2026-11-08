<?php

use App\Enums\CommandState;
use App\Enums\RunnerState;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\RunnerRecovery;
use App\Services\Threads\ThreadStreams;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $account = ClaudeAccount::factory()->create();
    $this->thread = Thread::factory()->paused()->create(['claude_account_id' => $account->id, 'runner_state' => RunnerState::Busy, 'append_system_prompt' => 'system rules']);
    $this->thread->sessions()->create(['session_id' => 'session-1', 'kind' => ThreadSessionKind::Main]);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
});

function runningRun(array $states, array $attributes = []): TaskRun
{
    return TaskRun::factory()->create([
        'yak_task_id' => test()->task->id,
        'thread_id' => test()->thread->id,
        'turn_state' => TurnState::Running,
        'prompt_uuid' => 'prompt-1',
        'prompt' => 'Fix the bug',
        'command_states' => $states,
        ...$attributes,
    ]);
}

function steer(TaskRun $run, string $uuid, string $text): PendingSteeringMessage
{
    $message = PendingSteeringMessage::query()->create(['root_task_id' => test()->task->id, 'text' => $text, 'source' => 'web', 'mode' => 'steer']);
    $message->forceFill(['uuid' => $uuid, 'task_run_id' => $run->id, 'sent_at' => now(), 'priority' => 'now'])->save();

    return $message;
}

function recoveryCommands(): array
{
    return test()->streams->commandsFor(test()->thread->channel());
}

it('resends the prompt when it never started', function () {
    runningRun(['prompt-1' => 'sent']);

    app(RunnerRecovery::class)->recover($this->thread);

    $commands = recoveryCommands();
    expect(array_column($commands, 'type'))->toBe(['kill', 'start_runner', 'turn'])
        ->and($commands[1]['start']['resume'])->toBe('session-1')
        ->and($commands[1]['start']['appendSystemPrompt'])->toBe('system rules')
        ->and($commands[2])->toMatchArray(['uuid' => 'prompt-1', 'text' => 'Fix the bug']);
});

it('sends the continuation message when the prompt had started', function () {
    $run = runningRun(['prompt-1' => 'started']);

    app(RunnerRecovery::class)->recover($this->thread);

    $turn = recoveryCommands()[2];
    $states = $run->fresh()->command_states;
    expect($turn['text'])->toBe('Yak restarted while you were working. Continue the task from where you left off.')
        ->and($turn['uuid'])->not->toBe('prompt-1')
        ->and($states['prompt-1'])->toBe(CommandState::Cancelled)
        ->and($states[$turn['uuid']])->toBe(CommandState::Sent);
});

it('resends unabsorbed steers with their original uuids', function () {
    $run = runningRun(['prompt-1' => 'started', 'steer-a' => 'queued', 'steer-b' => 'started', 'steer-c' => 'sent']);
    steer($run, 'steer-a', 'first');
    steer($run, 'steer-b', 'second');
    steer($run, 'steer-c', 'third');

    app(RunnerRecovery::class)->recover($this->thread);

    $steers = array_values(array_filter(recoveryCommands(), fn (array $command): bool => $command['type'] === 'steer'));
    expect(array_column($steers, 'uuid'))->toBe(['steer-a', 'steer-c'])
        ->and($steers[0])->toMatchArray(['text' => 'first', 'priority' => 'now']);
});

it('keeps the cost recorded before the restart', function () {
    $run = runningRun(['prompt-1' => 'started'], ['cost_usd' => 1.25]);

    app(RunnerRecovery::class)->recover($this->thread);

    expect((float) $run->fresh()->cost_usd)->toBe(1.25);
});

it('marks the turn as restarted', function () {
    $run = runningRun(['prompt-1' => 'sent']);

    app(RunnerRecovery::class)->recover($this->thread);

    expect($run->fresh()->restarted)->toBeTrue();
});

it("only resets an idle thread's runner state", function () {
    app(RunnerRecovery::class)->recover($this->thread);

    expect($this->thread->fresh()->runner_state)->toBe(RunnerState::None)
        ->and(recoveryCommands())->toBe([]);
});

it('clears background tasks the dead runner owned and sends the prompt before the steers', function () {
    $run = runningRun(['prompt-1' => 'sent', 'steer-a' => 'sent'], ['background_task_count' => 2, 'pending_background_results' => 1]);
    steer($run, 'steer-a', 'first');

    app(RunnerRecovery::class)->recover($this->thread);

    expect(array_column(recoveryCommands(), 'type'))->toBe(['kill', 'start_runner', 'turn', 'steer'])
        ->and($run->fresh()->background_task_count)->toBe(0)
        ->and($run->fresh()->pending_background_results)->toBe(0);
});
