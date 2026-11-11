<?php

use App\Events\ThreadActivity;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/NextGenStubs.php';

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
    app()->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers --once')->assertSuccessful();

    expect($handled)->toBe([$good->id]);
});
