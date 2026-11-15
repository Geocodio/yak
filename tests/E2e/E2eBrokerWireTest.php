<?php

use App\Providers\E2eServiceProvider;
use App\Services\E2e\E2eBrokerWire;
use App\Services\E2e\E2eRunnerScript;
use App\Services\Threads\RedisBrokerStreams;
use Illuminate\Support\Facades\Log;

afterEach(fn () => stopStartedLocalRedis());

it('reads commands with the broker group and deletes them', function (): void {
    $client = localRedisClient();
    $streams = new RedisBrokerStreams('streams_test');
    $streams->register('threads:7');
    $streams->command('threads:7', ['type' => 'start_runner']);
    $streams->command('threads:7', ['type' => 'turn', 'uuid' => 'u-1', 'text' => 'hello']);

    $wire = new E2eBrokerWire('streams_test');
    $commands = $wire->readCommands(['threads:7'], 10, 20);

    expect($commands)->toHaveCount(2)
        ->and($commands[0]['command']['type'])->toBe('start_runner')
        ->and($commands[1]['command']['uuid'])->toBe('u-1')
        ->and($client->xLen('threads:7:in'))->toBe(0)
        ->and($wire->readCommands(['threads:7'], 10, 20))->toBe([]);
});

it('writes entries the driver reads', function (): void {
    localRedisClient();
    $wire = new E2eBrokerWire('streams_test');

    expect($wire->startGeneration('threads:7'))->toBe(1);
    $wire->write('threads:7', ['type' => 'broker.runner_started', 'generation' => 1], true);
    $wire->write('threads:7', ['type' => 'runner.started'], false);

    $entries = (new RedisBrokerStreams('streams_test'))->read(['threads:7'], 'drivers-test', 0, 10);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->generation)->toBe(1)
        ->and($entries[0]->sequence)->toBe(1)
        ->and($entries[0]->fromBroker)->toBeTrue()
        ->and($entries[1]->sequence)->toBe(2)
        ->and($entries[1]->fromBroker)->toBeFalse();
});

it('continues the generation and sequence after a restart', function (): void {
    localRedisClient();
    $wire = new E2eBrokerWire('streams_test');
    $wire->startGeneration('threads:7');
    $wire->write('threads:7', ['type' => 'runner.started'], false);
    $wire->write('threads:7', ['type' => 'runner.done'], false);

    $restarted = new E2eBrokerWire('streams_test');
    $restarted->write('threads:7', ['type' => 'runner.started'], false);

    $entries = (new RedisBrokerStreams('streams_test'))->read(['threads:7'], 'drivers-test', 0, 10);

    expect($entries)->toHaveCount(3)
        ->and($entries[2]->generation)->toBe(1)
        ->and($entries[2]->sequence)->toBe(3)
        ->and($restarted->startGeneration('threads:7'))->toBe(2);
});

it('drops a broken or oversized command and says only its size', function (): void {
    $client = localRedisClient();
    $client->xAdd('threads:7:in', '*', ['command' => 'not json']);
    $client->xAdd('threads:7:in', '*', ['command' => json_encode(['pad' => str_repeat('x', 70_000)])]);
    Log::spy();

    $commands = (new E2eBrokerWire('streams_test'))->readCommands(['threads:7'], 10, 20);

    expect($commands)->toHaveCount(2)
        ->and($commands[0]['command'])->toBeNull()
        ->and($commands[0]['bytes'])->toBe(8)
        ->and($commands[1]['command'])->toBeNull()
        ->and($commands[1]['bytes'])->toBeGreaterThan(E2eBrokerWire::MAX_COMMAND_BYTES)
        ->and($client->xLen('threads:7:in'))->toBe(0);
});

it('serves at most two hundred channels', function (): void {
    $client = localRedisClient();
    foreach (range(1, 201) as $number) {
        $client->sAdd('broker:channels', "threads:{$number}");
    }

    expect((new E2eBrokerWire('streams_test'))->channels())->toHaveCount(200);
});

it('sets the heartbeat with an expiry', function (): void {
    $client = localRedisClient();
    (new E2eBrokerWire('streams_test'))->heartbeat();

    expect((int) $client->get('broker:alive'))->toBeGreaterThan(0)
        ->and($client->ttl('broker:alive'))->toBeGreaterThan(0)->toBeLessThanOrEqual(15);
});

it('answers a turn end to end in one pass', function (): void {
    $client = localRedisClient();
    config(['yak.e2e.enabled' => true]);
    (new E2eServiceProvider(app()))->boot();
    $streams = new RedisBrokerStreams('streams_test');
    $streams->register('threads:7');
    $streams->command('threads:7', ['type' => 'start_runner', 'container' => 'thread-7', 'start' => ['type' => 'start']]);
    $streams->command('threads:7', ['type' => 'turn', 'uuid' => 'u-1', 'text' => 'hello']);

    $this->artisan('yak:e2e-runner', ['--pace' => '0', '--once' => true, '--connection' => 'streams_test'])->assertExitCode(0);

    $script = new E2eRunnerScript(null, 0.0);
    $expected = count($script->respond('threads:7', ['type' => 'start_runner'], 1))
        + count($script->respond('threads:7', ['type' => 'turn', 'uuid' => 'u-1', 'text' => 'hello'], 1));
    expect($expected)->toBeGreaterThan(4);

    expect($client->xLen('threads:7:out'))->toBe($expected);
});
