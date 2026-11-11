<?php

use App\Enums\RunnerState;
use App\Events\RunnerExited;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/NextGenStubs.php';

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
