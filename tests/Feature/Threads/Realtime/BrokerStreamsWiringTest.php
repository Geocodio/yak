<?php

use App\Providers\AppServiceProvider;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\RedisBrokerStreams;

it('wires the broker streams to the command connection that has a read timeout', function () {
    // The test suite binds a fake; registering the provider again restores the production binding.
    (new AppServiceProvider(app()))->register();
    $streams = app(BrokerStreams::class);
    $commandConnection = (new ReflectionProperty(RedisBrokerStreams::class, 'commandConnection'))->getValue($streams);

    expect($streams)->toBeInstanceOf(RedisBrokerStreams::class)
        ->and($commandConnection)->toBe('threads-commands')
        ->and(config('database.redis.threads-commands.read_timeout'))->toBeGreaterThan(0);
});
