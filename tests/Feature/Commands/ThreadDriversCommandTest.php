<?php

use App\DataTransferObjects\StreamEntry;
use App\Models\Thread;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use Tests\Support\FakeThreadStreams;

it('acks only after handling', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $thread = Thread::factory()->create();
    $streams->register($thread->channel());
    $streams->pushOutbound($thread->channel(), ['type' => 'runner', 'event' => 'started']);

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->once()->andThrow(new RuntimeException('boom'));
    $driver->shouldReceive('tick');
    $this->app->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    expect($streams->read([$thread->channel()], 'c', 0, 10))->toHaveCount(1);

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->once();
    $driver->shouldReceive('tick');
    $this->app->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    expect($streams->read([$thread->channel()], 'c', 0, 10))->toHaveCount(0);
});

it('does not let one failing entry block another thread', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $poisoned = Thread::factory()->create();
    $healthy = Thread::factory()->create();
    $streams->register($poisoned->channel());
    $streams->register($healthy->channel());
    $streams->pushOutbound($poisoned->channel(), ['type' => 'poison']);
    $streams->pushOutbound($healthy->channel(), ['type' => 'fine']);

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andReturnUsing(function (StreamEntry $entry) {
        if ($entry->line['type'] === 'poison') {
            throw new RuntimeException('boom');
        }
    });
    $driver->shouldReceive('tick');
    $this->app->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();

    expect($streams->read([$healthy->channel()], 'c', 0, 10))->toHaveCount(0)
        ->and($streams->read([$poisoned->channel()], 'c', 0, 10))->toHaveCount(1);
});

it('moves an entry that keeps failing to the dead letters and fails only its turn', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $thread = Thread::factory()->create();
    $streams->register($thread->channel());
    $streams->pushOutbound($thread->channel(), ['type' => 'poison']);

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->times(3)->andThrow(new RuntimeException('boom'));
    $driver->shouldReceive('tick');
    $driver->shouldReceive('failTurnOf')->once();
    $this->app->instance(ThreadDriver::class, $driver);

    foreach (range(1, 3) as $ignored) {
        $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    }

    expect($streams->deadLettered)->toHaveCount(1)
        ->and($streams->deadLettered[0][1])->toContain('boom')
        ->and($streams->read([$thread->channel()], 'c', 0, 10))->toHaveCount(0);
});

it('acknowledges an entry even when dead-lettering it fails', function () {
    $streams = Mockery::mock(FakeThreadStreams::class)->makePartial();
    $this->app->instance(ThreadStreams::class, $streams);
    $thread = Thread::factory()->create();
    $streams->register($thread->channel());
    $streams->pushOutbound($thread->channel(), ['type' => 'poison']);
    $streams->shouldReceive('deadLetter')->andThrow(new RuntimeException('encode'));

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->times(3)->andThrow(new RuntimeException('boom'));
    $driver->shouldReceive('tick');
    $driver->shouldReceive('failTurnOf')->once();
    $this->app->instance(ThreadDriver::class, $driver);

    foreach (range(1, 3) as $ignored) {
        $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    }

    expect($streams->read([$thread->channel()], 'c', 0, 10))->toHaveCount(0);
});

it('holds back later entries of a channel while an earlier one is pending', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $thread = Thread::factory()->create();
    $streams->register($thread->channel());
    $streams->pushOutbound($thread->channel(), ['type' => 'first']);
    $streams->pushOutbound($thread->channel(), ['type' => 'second']);

    $handled = [];
    $failedOnce = false;
    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->andReturnUsing(function (StreamEntry $entry) use (&$handled, &$failedOnce) {
        if ($entry->line['type'] === 'first' && ! $failedOnce) {
            $failedOnce = true;

            throw new RuntimeException('transient');
        }

        $handled[] = $entry->line['type'];
    });
    $driver->shouldReceive('tick');
    $this->app->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    expect($handled)->toBe([]);

    $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    expect($handled)->toBe(['first', 'second']);
});
