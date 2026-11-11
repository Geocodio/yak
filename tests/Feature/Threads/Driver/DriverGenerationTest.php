<?php

use App\Enums\RunnerState;
use App\Events\RunnerExited;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
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

it('rebinds the placeholder to the generation of a started runner', function () {
    $thread = generationThread(1);
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('rebindGeneration')->once()->with(Mockery::on(fn ($given) => $given->runner_generation === 2));
    app()->instance(AuthProxyPlaceholders::class, $placeholders);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 2, 1, ['type' => 'runner', 'event' => 'started']);

    app(ThreadDriver::class)->handle($entry);
});
