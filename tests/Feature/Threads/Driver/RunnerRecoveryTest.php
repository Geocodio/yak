<?php

use App\Enums\RunnerState;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadSession;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Threads\RunnerRecovery;
use App\Services\Threads\SteeringText;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

/**
 * A thread whose runner is gone in the middle of a turn, with the commands mocked.
 *
 * @param  array<string, mixed>  $runAttributes
 * @return array{0: Thread, 1: TaskRun, 2: MockInterface}
 */
function lostRunnerTurn(array $runAttributes = [], TurnMessageState $promptState = TurnMessageState::Started): array
{
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1', 'prompt' => 'Do the thing', 'recorded_cost_usd' => 0.5, ...$runAttributes]);
    $thread->forceFill(['runner_state' => RunnerState::None, 'append_system_prompt' => 'Be brief.'])->save();
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => 'session-1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'prompt-1', 'state' => $promptState]);

    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('revokePlaceholder')->byDefault();
    app()->instance(ThreadCommands::class, $commands);

    return [$thread, $run, $commands];
}

it('resumes and sends the continuation when the prompt had started', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    $commands->shouldReceive('kill')->once()->ordered();
    $commands->shouldReceive('startRunner')->once()->ordered()->with(Mockery::on(fn ($given) => $given->is($thread)), Mockery::any(), 'session-1', 'Be brief.');
    $commands->shouldReceive('turn')->once()->ordered()->with(Mockery::any(), Mockery::type('string'), RunnerRecovery::CONTINUATION_TEXT);

    app(RunnerRecovery::class)->recover($thread);

    $continuation = ThreadTurnMessage::query()->where('task_run_id', $run->id)->where('uuid', '!=', 'prompt-1')->first();

    expect($continuation->kind)->toBe(TurnMessageKind::Prompt)
        ->and($continuation->state)->toBe(TurnMessageState::Sent)
        ->and(ThreadTurnMessage::query()->where('uuid', 'prompt-1')->value('state'))->toBe(TurnMessageState::Cancelled);
});

it('re-sends an unstarted prompt with its uuid', function () {
    [$thread, , $commands] = lostRunnerTurn(promptState: TurnMessageState::Queued);
    $commands->shouldReceive('kill')->once();
    $commands->shouldReceive('startRunner')->once();
    $commands->shouldReceive('turn')->once()->with(Mockery::any(), 'prompt-1', 'Do the thing');

    app(RunnerRecovery::class)->recover($thread);

    expect(ThreadTurnMessage::query()->count())->toBe(1);
});

it('re-sends unstarted steers with original uuids and skips started ones', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    $root = YakTask::factory()->create();

    foreach (['steer-sent' => TurnMessageState::Sent, 'steer-queued' => TurnMessageState::Queued, 'steer-started' => TurnMessageState::Started] as $uuid => $state) {
        $pending = PendingSteeringMessage::create(['root_task_id' => $root->id, 'text' => "text of {$uuid}", 'source' => 'thread', 'mode' => 'steer', 'uuid' => $uuid]);
        ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id, 'uuid' => $uuid, 'state' => $state, 'pending_steering_message_id' => $pending->id]);
    }

    $commands->shouldReceive('kill')->once();
    $commands->shouldReceive('startRunner')->once();
    $commands->shouldReceive('steer')->once()->with(Mockery::any(), 'steer-sent', SteeringText::PREAMBLE . 'text of steer-sent');
    $commands->shouldReceive('steer')->once()->with(Mockery::any(), 'steer-queued', SteeringText::PREAMBLE . 'text of steer-queued');
    $commands->shouldReceive('turn')->once();

    app(RunnerRecovery::class)->recover($thread);

    expect(ThreadTurnMessage::query()->where('uuid', 'steer-started')->value('state'))->toBe(TurnMessageState::Cancelled);
});

it('keeps recorded cost and counts the restart', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    $commands->shouldReceive('kill', 'startRunner', 'turn')->once();

    app(RunnerRecovery::class)->recover($thread);

    expect((float) $run->refresh()->recorded_cost_usd)->toBe(0.5)
        ->and($run->restart_count)->toBe(1)
        ->and($run->turn_state)->toBe(TurnState::Running);
});

it('fails the turn past the restart cap', function () {
    Event::fake([TurnFailed::class]);
    config(['yak.threads.runner_restart_cap' => 2]);
    [$thread, $run, $commands] = lostRunnerTurn(['restart_count' => 2]);
    $commands->shouldNotReceive('startRunner');
    $events = [];

    app(RunnerRecovery::class)->recover($thread, $events);
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Failed);
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('waits for the backoff before restarting', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    $commands->shouldReceive('kill', 'startRunner', 'turn')->once();

    app(RunnerRecovery::class)->recover($thread);
    $thread->save();

    $thread->forceFill(['runner_state' => RunnerState::None])->save();
    app(RunnerRecovery::class)->recoverDue();

    expect($run->refresh()->restart_count)->toBe(1);

    $commands->shouldReceive('kill', 'startRunner', 'turn')->once();
    $this->travel(3)->seconds();
    app(RunnerRecovery::class)->recoverDue();

    expect($run->refresh()->restart_count)->toBe(2);
});

it('restarts only for the current generation', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    $thread->forceFill(['runner_generation' => 2, 'runner_state' => RunnerState::Busy])->save();
    $commands->shouldNotReceive('startRunner');

    replayRunnerFixture($thread, 'runner-exit-mid-turn');

    expect($run->refresh()->restart_count)->toBe(0)
        ->and($thread->refresh()->runner_state)->toBe(RunnerState::Busy);
});

it('recovers from a runner-exit fixture of the current generation', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    $commands->shouldReceive('kill')->once();
    $commands->shouldReceive('startRunner')->once();
    $commands->shouldReceive('turn')->once();

    replayRunnerFixture($thread, 'runner-exit-mid-turn');

    expect($run->refresh()->restart_count)->toBe(1);
});

/**
 * Recovers a turn whose prompt had started and returns the continuation uuid.
 */
function recoverStartedPrompt(Thread $thread, TaskRun $run, MockInterface $commands): string
{
    $commands->shouldReceive('kill', 'startRunner', 'turn')->once();
    ThreadTurns::trackUuid($run, 'prompt-1');
    $tracker = ThreadTurns::trackerFor($run->refresh());
    $tracker->apply(['type' => 'command_lifecycle', 'command_uuid' => 'prompt-1', 'state' => 'started'], CarbonImmutable::now());
    $run->forceFill(['tracker_state' => json_encode($tracker->state())])->save();

    app(RunnerRecovery::class)->recover($thread);
    $thread->save();

    return (string) ThreadTurnMessage::query()->where('task_run_id', $run->id)->where('uuid', '!=', 'prompt-1')->value('uuid');
}

it('ends a recovered turn through the grace window', function () {
    Event::fake([TurnCompleted::class]);
    [$thread, $run, $commands] = lostRunnerTurn();
    $thread->forceFill(['runner_state' => RunnerState::Busy])->save();
    $continuation = recoverStartedPrompt($thread, $run, $commands);
    $thread->forceFill(['runner_state' => RunnerState::Busy])->save();

    pushRunnerLine($thread, 1, ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'session-1']);
    pushRunnerLine($thread, 2, ['type' => 'command_lifecycle', 'command_uuid' => $continuation, 'state' => 'completed']);
    $this->travel(31)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
});

it('does not end a recovered turn before the continuation completes', function () {
    [$thread, $run, $commands] = lostRunnerTurn();
    recoverStartedPrompt($thread, $run, $commands);
    $thread->forceFill(['runner_state' => RunnerState::Busy])->save();

    pushRunnerLine($thread, 1, ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'session-1']);
    $this->travel(31)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);
});
