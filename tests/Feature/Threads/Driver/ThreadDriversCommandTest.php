<?php

use App\Events\ThreadActivity;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

function streamedThread(FakeBrokerStreams $streams): Thread
{
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => 1])->save();
    $streams->register('threads:' . $thread->id);

    return $thread;
}

it('acks handled entries', function () {
    Event::fake([ThreadActivity::class]);
    $streams = app(BrokerStreams::class);
    $thread = streamedThread($streams);
    $streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'assistant']);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();
    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    Event::assertDispatchedTimes(ThreadActivity::class, 1);
    expect($streams->read(['threads:' . $thread->id], 'other', 0, 10))->toBe([]);
});

it('retries a failing entry then dead letters it and keeps going', function () {
    $streams = app(BrokerStreams::class);
    $thread = streamedThread($streams);
    $bad = $streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'bad']);
    $good = $streams->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'good']);
    $handled = [];
    $failed = [];

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andReturnUsing(function ($entry) use ($bad, &$handled) {
        if ($entry->id === $bad->id) {
            throw new RuntimeException('boom');
        }
        $handled[] = $entry->id;
    });
    $driver->shouldReceive('failTurnOf')->once()->andReturnUsing(function ($entry) use (&$failed) {
        $failed[] = $entry->id;
    });
    $driver->shouldReceive('tick');
    $driver->shouldReceive('trimAfterAck');
    app()->instance(ThreadDriver::class, $driver);

    foreach (range(1, 3) as $pass) {
        $this->artisan('yak:thread-drivers --once')->assertSuccessful();
    }

    expect($handled)->toBe([$good->id])
        ->and($failed)->toBe([$bad->id])
        ->and($streams->deadLetters('threads:' . $thread->id))->toHaveCount(1)
        ->and($streams->deadLetters('threads:' . $thread->id)[0]['reason'])->toBe('boom');
});

it('isolates one thread from another', function () {
    $streams = app(BrokerStreams::class);
    $broken = streamedThread($streams);
    $healthy = streamedThread($streams);
    $streams->pushOut('threads:' . $broken->id, 1, 1, ['type' => 'bad']);
    $good = $streams->pushOut('threads:' . $healthy->id, 1, 1, ['type' => 'good']);
    $handled = [];

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andReturnUsing(function ($entry) use ($broken, &$handled) {
        if ($entry->channel === 'threads:' . $broken->id) {
            throw new RuntimeException('boom');
        }
        $handled[] = $entry->id;
    });
    $driver->shouldReceive('tick');
    $driver->shouldReceive('trimAfterAck');
    app()->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($handled)->toBe([$good->id]);
});

it('does not apply a later entry of a channel while an earlier one is still failing', function () {
    $streams = app(BrokerStreams::class);
    $thread = streamedThread($streams);
    $a = $streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'a']);
    $b = $streams->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'b']);
    $handled = [];
    $failedOnce = false;

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andReturnUsing(function ($entry) use ($a, &$handled, &$failedOnce) {
        if ($entry->id === $a->id && ! $failedOnce) {
            $failedOnce = true;

            throw new RuntimeException('boom');
        }
        $handled[] = $entry->id;
    });
    $driver->shouldReceive('tick');
    $driver->shouldReceive('trimAfterAck');
    app()->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($handled)->toBe([])
        ->and($streams->read(['threads:' . $thread->id], gethostname() . '-' . getmypid(), 0, 10))->toHaveCount(2);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($handled)->toBe([$a->id, $b->id])
        ->and($streams->read(['threads:' . $thread->id], gethostname() . '-' . getmypid(), 0, 10))->toBe([]);
});

it('keeps entries of other channels flowing when one channel is blocked', function () {
    $streams = app(BrokerStreams::class);
    $blocked = streamedThread($streams);
    $healthy = streamedThread($streams);
    $streams->pushOut('threads:' . $blocked->id, 1, 1, ['type' => 'a']);
    $streams->pushOut('threads:' . $blocked->id, 1, 2, ['type' => 'b']);
    $c = $streams->pushOut('threads:' . $healthy->id, 1, 1, ['type' => 'c']);
    $handled = [];

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andReturnUsing(function ($entry) use ($blocked, &$handled) {
        if ($entry->channel === 'threads:' . $blocked->id) {
            throw new RuntimeException('boom');
        }
        $handled[] = $entry->id;
    });
    $driver->shouldReceive('tick');
    $driver->shouldReceive('trimAfterAck');
    app()->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($handled)->toBe([$c->id])
        ->and($streams->read(['threads:' . $healthy->id], 'x', 0, 10))->toBe([]);
});

it('never trims past a failing entry', function () {
    $streams = app(BrokerStreams::class);
    $thread = streamedThread($streams);
    $streams->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'a']);
    $streams->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'b']);

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
    $driver->shouldReceive('tick');
    $driver->shouldNotReceive('trimAfterAck');
    app()->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($streams->read(['threads:' . $thread->id], gethostname() . '-' . getmypid(), 0, 10))->toHaveCount(2);
});
