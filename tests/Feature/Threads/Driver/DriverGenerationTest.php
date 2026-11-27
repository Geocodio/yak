<?php

use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Events\RunnerExited;
use App\Events\ThreadActivity;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

function generationThread(int $generation): Thread
{
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => $generation, 'runner_state' => RunnerState::Busy])->save();

    return $thread;
}

it('ignores a line from an older generation', function () {
    Event::fake();
    $thread = generationThread(2);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'runner.started']);

    app(ThreadDriver::class)->handle($entry);

    expect($thread->refresh()->runner_generation)->toBe(2)
        ->and($thread->runner_state)->toBe(RunnerState::Busy)
        ->and((int) $thread->getAttribute('applied_sequence'))->toBe(1);
});

it('lets a newer runner.started become current', function () {
    $thread = generationThread(1);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 2, 1, ['type' => 'runner', 'event' => 'started']);

    app(ThreadDriver::class)->handle($entry);

    expect($thread->refresh()->runner_generation)->toBe(2)
        ->and($thread->runner_state)->toBe(RunnerState::Idle)
        ->and($thread->runner_started_at)->not->toBeNull();
});

it('ignores runner output that claims to be a broker line', function () {
    Event::fake();
    $thread = generationThread(1);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1], fromBroker: false);

    app(ThreadDriver::class)->handle($entry);

    Event::assertNotDispatched(RunnerExited::class);
    expect($thread->refresh()->runner_state)->toBe(RunnerState::Busy);
});

it('revokes the placeholder and dispatches RunnerExited once', function () {
    Event::fake([RunnerExited::class]);
    $thread = generationThread(1);
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('revoke')->once();
    app()->instance(AuthProxyPlaceholders::class, $placeholders);
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);
    $exited = $streams->pushOut('threads:' . $thread->id, 1, 3, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1, 'exit_code' => 0], fromBroker: true);

    $driver->handle($exited);
    $driver->handle($exited);

    Event::assertDispatchedTimes(RunnerExited::class, 1);
    expect($thread->refresh()->runner_state)->toBe(RunnerState::None);
});

it('ignores a runner_exited of a stale generation', function () {
    Event::fake([RunnerExited::class]);
    $thread = generationThread(2);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 2, 3, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1], fromBroker: true);

    app(ThreadDriver::class)->handle($entry);

    Event::assertNotDispatched(RunnerExited::class);
    expect($thread->refresh()->runner_state)->toBe(RunnerState::Busy);
});

it('lets an idle runner exit without restarting it', function () {
    Event::fake([RunnerExited::class]);
    $thread = generationThread(1);
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('revokePlaceholder')->once();
    $commands->shouldNotReceive('startRunner');
    app()->instance(ThreadCommands::class, $commands);
    $exited = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1, 'exit_code' => 0], fromBroker: true);

    app(ThreadDriver::class)->handle($exited);

    expect($thread->refresh()->runner_state)->toBe(RunnerState::None);
});

it('recovers a turn whose message found no runner', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1', 'prompt' => 'Go']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'prompt-1']);
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('revokePlaceholder')->once();
    $commands->shouldReceive('kill')->once();
    $commands->shouldReceive('startRunner')->once();
    $commands->shouldReceive('turn')->once()->with(Mockery::any(), 'prompt-1', 'Go');
    app()->instance(ThreadCommands::class, $commands);

    $noRunner = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker', 'event' => 'no_runner', 'uuid' => 'prompt-1'], fromBroker: true);
    app(ThreadDriver::class)->handle($noRunner);

    expect($run->refresh()->restart_count)->toBe(1);
});

it('ignores a no_runner for a uuid the turn is not waiting on', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'prompt-1', 'state' => 'started']);
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldNotReceive('startRunner');
    app()->instance(ThreadCommands::class, $commands);

    $noRunner = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker', 'event' => 'no_runner', 'uuid' => 'prompt-1'], fromBroker: true);
    app(ThreadDriver::class)->handle($noRunner);

    expect($run->refresh()->restart_count)->toBe(0);
});

it('forgets the runner of an idle thread when the broker restarts', function () {
    [$thread, $run] = runningTurnFor();
    $run->forceFill(['turn_state' => TurnState::Completed])->save();
    $thread->forceFill(['runner_state' => RunnerState::Idle])->save();
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('revokePlaceholder')->once();
    $commands->shouldNotReceive('startRunner');
    app()->instance(ThreadCommands::class, $commands);

    $restarted = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker', 'event' => 'restarted'], fromBroker: true);
    app(ThreadDriver::class)->handle($restarted);

    expect($thread->refresh()->runner_state)->toBe(RunnerState::None);
});

it('records the turn as running before the command reaches the broker', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1', 'prompt' => 'Go']);
    $run->forceFill(['turn_state' => TurnState::Pending])->save();
    $thread->forceFill(['runner_state' => RunnerState::Idle])->save();
    $stateWhenSent = null;
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('setModel')->byDefault();
    $commands->shouldReceive('turn')->once()->andReturnUsing(function () use ($run, &$stateWhenSent): void {
        $stateWhenSent = $run->fresh()->getAttribute('turn_state');
    });
    app()->instance(ThreadCommands::class, $commands);

    app(ThreadTurns::class)->dispatchTurn($thread, $run);

    expect($stateWhenSent)->toBe(TurnState::Running);
});

it('starts a runner with the current standing instructions, not the stored ones', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1', 'prompt' => 'Go']);
    $run->forceFill(['turn_state' => TurnState::Pending])->save();
    $thread->forceFill(['runner_state' => RunnerState::None, 'append_system_prompt' => 'Old instructions.'])->save();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo]);
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('startRunner')->once()->with(Mockery::any(), Mockery::any(), Mockery::any(), YakPromptBuilder::threadSystemPrompt($task, $thread), Mockery::any());
    $commands->shouldReceive('turn', 'setModel')->byDefault();
    app()->instance(ThreadCommands::class, $commands);

    app(ThreadTurns::class)->dispatchTurn($thread, $run);

    expect($thread->refresh()->append_system_prompt)->not->toBe('Old instructions.');
});

it('keeps the instructions a sub-thread was given', function () {
    $parent = Thread::factory()->create();
    $child = Thread::factory()->create(['parent_thread_id' => $parent->id, 'append_system_prompt' => 'Child rules.']);
    YakTask::factory()->create(['thread_id' => $child->id]);

    expect(YakPromptBuilder::refreshThreadSystemPrompt($child))->toBe('Child rules.');
});

it('starts the runner again when the broker restarts while a recovery start is unconfirmed', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1', 'prompt' => 'Go', 'restart_count' => 1]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'prompt-1', 'state' => 'cancelled']);
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('revokePlaceholder')->once();
    $commands->shouldReceive('kill')->once();
    $commands->shouldReceive('startRunner')->once();
    $commands->shouldReceive('turn')->once();
    app()->instance(ThreadCommands::class, $commands);

    $restarted = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'broker', 'event' => 'restarted'], fromBroker: true);
    app(ThreadDriver::class)->handle($restarted);

    expect($run->refresh()->restart_count)->toBe(2);
});

function streamDeltaLine(string $text): array
{
    return ['type' => 'stream_event', 'api_message_id' => 'm1', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]];
}

it('runs no queries beyond the generation check for a stream line', function () {
    Event::fake();
    [$thread] = runningTurnFor();
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, streamDeltaLine('hi'));
    $driver = app(ThreadDriver::class);
    $driver->handle($entry);

    $second = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 2, streamDeltaLine('there'));
    DB::enableQueryLog();
    $driver->handle($second);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn ($query) => str_contains($query, 'task_runs')))->toBeEmpty();
});

it('leaves the turn tracker untouched for a stream line', function () {
    Event::fake();
    [$thread, $run] = runningTurnFor();
    $before = $run->refresh()->tracker_state;
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, streamDeltaLine('hi'));

    app(ThreadDriver::class)->handle($entry);

    expect($run->refresh()->tracker_state)->toBe($before);
});

it('neither locks nor writes the thread row for a stream line within a second of the last activity write', function () {
    Event::fake();
    [$thread] = runningTurnFor();
    $driver = app(ThreadDriver::class);
    $driver->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, streamDeltaLine('hi')));
    $touchedAt = $thread->refresh()->last_activity_at;

    $this->travel(500)->milliseconds();
    DB::enableQueryLog();
    $driver->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 2, streamDeltaLine('there')));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $this->travel(600)->milliseconds();
    $driver->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 3, streamDeltaLine('!')));

    expect($queries->filter(fn ($query) => str_contains(strtolower($query), 'for update') || str_starts_with(strtolower($query), 'update')))->toBeEmpty()
        ->and($touchedAt)->not->toBeNull()
        ->and($thread->refresh()->last_activity_at->greaterThan($touchedAt))->toBeTrue();
    Event::assertDispatchedTimes(ThreadActivity::class, 3);
});
