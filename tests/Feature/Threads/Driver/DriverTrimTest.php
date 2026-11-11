<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\RunnerState;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\SessionSync;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadSandbox;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

it('trims to the catch-up window', function () {
    $this->freezeTime();
    $cutoff = now()->subMinutes((int) config('yak.threads.stream_catchup_minutes'))->getTimestampMs() . '-0';
    $streams = Mockery::mock(BrokerStreams::class);
    $streams->shouldReceive('trimBefore')->once()->with('threads:1', $cutoff);
    app()->instance(BrokerStreams::class, $streams);

    app(ThreadDriver::class)->trimAfterAck(new StreamEntry('threads:1', now()->getTimestampMs() . '-0', 1, 1, []));
});

it('never trims past the acknowledged entry', function () {
    $streams = Mockery::mock(BrokerStreams::class);
    $streams->shouldReceive('trimBefore')->once()->with('threads:1', '5-0');
    app()->instance(BrokerStreams::class, $streams);

    app(ThreadDriver::class)->trimAfterAck(new StreamEntry('threads:1', '5-0', 1, 1, []));
});

it('deletes the streams when the runner ends for good', function () {
    [$thread] = runningTurnFor(['turn_state' => 'completed']);
    $thread->forceFill(['runner_state' => RunnerState::Stopping])->save();
    $streams = app(BrokerStreams::class);
    $streams->register('threads:' . $thread->id);
    $streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'assistant']);

    app(ThreadDriver::class)->handle($streams->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1, 'exit_code' => 0], true));

    expect($streams->channels('threads:'))->toBe([])
        ->and($streams->outbound('threads:' . $thread->id))->toBe([]);
});

it('keeps the streams when the runner dies mid-turn', function () {
    [$thread] = runningTurnFor();
    $streams = app(BrokerStreams::class);
    $streams->register('threads:' . $thread->id);

    app(ThreadDriver::class)->handle($streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1, 'exit_code' => 137], true));

    expect($streams->channels('threads:'))->toBe(['threads:' . $thread->id]);
});

it('syncs the session and pauses the sandbox after a turn', function () {
    $sessions = Mockery::mock(SessionSync::class);
    $sessions->shouldReceive('syncFromSandbox')->once();
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('pauseIfIdle')->once();
    app()->instance(SessionSync::class, $sessions);
    app()->instance(ThreadSandbox::class, $sandbox);
    [$thread] = runningTurnFor();

    replayRunnerFixture($thread, 'natural-turn-end');
});
