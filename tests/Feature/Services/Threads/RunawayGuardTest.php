<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Jobs\SendNotificationJob;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\RunawayGuard;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);
    Queue::fake();
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    config(['yak.max_turns' => 3, 'yak.threads.turn_wall_clock_minutes' => 120]);

    $this->account = ClaudeAccount::factory()->create();
    $this->thread = Thread::factory()->paused()->create([
        'claude_account_id' => $this->account->id,
        'lock_holder' => ThreadLockHolder::Sandbox,
        'runner_state' => RunnerState::Busy,
    ]);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
    $this->run = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'turn_state' => TurnState::Running,
        'prompt_uuid' => 'prompt-1',
        'command_states' => ['prompt-1' => 'started'],
        'agent_started_at' => now(),
        'assistant_messages' => 0,
        'cost_usd' => 0,
    ]);
    $this->thread->forceFill(['lock_turn_id' => $this->run->id])->save();
});

function assistantLine(int $number): array
{
    return ['type' => 'assistant', 'message' => ['id' => "m{$number}", 'model' => 'opus', 'content' => [['type' => 'text', 'text' => "Step {$number}"]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]];
}

function driveAssistantLines(Thread $thread, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        app(ThreadDriver::class)->handle(new StreamEntry($thread->channel(), $i . '-0', 1, $i, assistantLine($i)));
    }
}

it('trips on the assistant turn limit', function () {
    $this->run->forceFill(['assistant_messages' => 2])->save();
    expect(app(RunawayGuard::class)->check($this->run))->toBeNull();

    $this->run->forceFill(['assistant_messages' => 3])->save();
    expect(app(RunawayGuard::class)->check($this->run))->toBe('assistant_turns');

    $this->run->forceFill(['assistant_messages' => 0])->save();
    driveAssistantLines($this->thread, 3);

    expect($this->run->fresh()->turn_state)->toBe(TurnState::StoppedByGuard)
        ->and($this->run->fresh()->guard_reason)->toBe('assistant_turns');
});

it('trips on the wall clock from tick without new output', function () {
    $this->run->forceFill(['agent_started_at' => now()->subMinutes(119)])->save();
    app(ThreadDriver::class)->tick();
    expect($this->run->fresh()->turn_state)->toBe(TurnState::Running);

    $this->run->forceFill(['agent_started_at' => now()->subMinutes(121)])->save();
    app(ThreadDriver::class)->tick();

    expect($this->run->fresh()->turn_state)->toBe(TurnState::StoppedByGuard)
        ->and($this->run->fresh()->guard_reason)->toBe('wall_clock');
});

it('sends interrupt and marks the turn stopped by the guard', function () {
    $this->run->forceFill(['agent_started_at' => now()->subMinutes(130)])->save();
    app(ThreadDriver::class)->tick();

    expect(array_column($this->streams->commandsFor($this->thread->channel()), 'type'))->toBe(['interrupt'])
        ->and($this->thread->fresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($this->thread->fresh()->runningTurn())->toBeNull();
    Event::assertDispatched(TurnFailed::class, 1);

    app(ThreadDriver::class)->tick();
    Event::assertDispatched(TurnFailed::class, 1);
});

it('notifies the source for an unattended task', function () {
    $this->run->forceFill(['agent_started_at' => now()->subMinutes(130), 'driver_user_id' => null])->save();
    app(ThreadDriver::class)->tick();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->task->is($this->task)
        && str_contains($job->message, 'Yak stopped this turn after 120 minutes')
        && str_contains($job->message, 'press Continue'));
});

it('continues with a new turn with fresh limits', function () {
    $this->thread->forceFill(['sandbox_container' => 'yak-thread-1'])->save();
    $this->run->forceFill(['assistant_messages' => 3])->save();
    driveAssistantLines($this->thread, 1);
    expect($this->run->fresh()->turn_state)->toBe(TurnState::StoppedByGuard);

    $user = User::factory()->create();
    $this->actingAs($user)->post(route('tasks.continue', $this->task))->assertRedirect();

    $new = $this->thread->fresh()->runningTurn();
    expect($new)->not->toBeNull()
        ->and($new->id)->not->toBe($this->run->id)
        ->and($new->assistant_messages)->toBe(0)
        ->and($new->guard_reason)->toBeNull()
        ->and($new->driver_user_id)->toBe($user->id);
    expect(collect($this->streams->commandsFor($this->thread->channel()))->last()['text'])->toBe('Continue from where you stopped.');
});
