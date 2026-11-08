<?php

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

    expect(fn () => $this->artisan('yak:thread-drivers', ['--once' => true]))->toThrow(RuntimeException::class);
    expect($streams->read([$thread->channel()], 'c', 0, 10))->toHaveCount(1);

    $driver = Mockery::mock(ThreadDriver::class);
    $driver->shouldReceive('handle')->once();
    $driver->shouldReceive('tick');
    $this->app->instance(ThreadDriver::class, $driver);

    $this->artisan('yak:thread-drivers', ['--once' => true])->assertSuccessful();
    expect($streams->read([$thread->channel()], 'c', 0, 10))->toHaveCount(0);
});
