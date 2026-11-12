<?php

use App\DataTransferObjects\StreamEntry;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\RedisBrokerStreams;
use Illuminate\Support\Facades\Redis;
use Redis as RedisClient;

/**
 * Starts a throwaway redis-server on a free port in a temp directory. Returns
 * null when no redis-server is installed.
 *
 * @return array{process: resource, port: int, directory: string}|null
 */
function startLocalRedis(): ?array
{
    $binary = trim((string) shell_exec('command -v redis-server'));

    if ($binary === '') {
        return null;
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $directory = sys_get_temp_dir() . '/yak-redis-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);

    $process = proc_open(
        [$binary, '--port', (string) $port, '--bind', '127.0.0.1', '--dir', $directory, '--save', '', '--appendonly', 'no'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    // Blocks until the server announces it accepts connections.
    while (($line = fgets($pipes[1])) !== false) {
        if (str_contains($line, 'Ready to accept connections')) {
            return ['process' => $process, 'port' => $port, 'directory' => $directory];
        }
    }

    proc_terminate($process);

    return null;
}

/** @var list<array{process: resource, directory: string}> */
$GLOBALS['startedRedisServers'] = [];

function stopLocalRedis(array $redis): void
{
    proc_terminate($redis['process']);
    proc_close($redis['process']);
    array_map('unlink', glob($redis['directory'] . '/*') ?: []);
    @rmdir($redis['directory']);
}

afterEach(function () {
    foreach ($GLOBALS['startedRedisServers'] as $redis) {
        stopLocalRedis($redis);
    }
    $GLOBALS['startedRedisServers'] = [];
    Redis::purge('streams_test');
});

/**
 * Points a `streams_test` connection at a fresh local redis-server and returns
 * its client, or skips the test when redis-server is not installed.
 */
function localRedisClient(): RedisClient
{
    $redis = startLocalRedis();

    if ($redis === null) {
        test()->markTestSkipped('redis-server is not installed, so RedisBrokerStreams is not tested against a real Redis.');
    }

    $GLOBALS['startedRedisServers'][] = $redis;
    config(['database.redis.streams_test' => ['host' => '127.0.0.1', 'port' => $redis['port'], 'database' => 0, 'options' => ['prefix' => '']]]);
    // The Redis manager copies its config when it is built.
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');

    return Redis::connection('streams_test')->client();
}

/**
 * Both implementations behave the same: returns the streams and a function
 * that plays a broker line into a channel's out stream.
 *
 * @return array{0: BrokerStreams, 1: Closure(string, int, int, array<string, mixed>, bool=): mixed}
 */
function makeStreams(string $kind): array
{
    if ($kind === 'fake') {
        $streams = new FakeBrokerStreams;

        return [$streams, fn (string $channel, int $generation, int $sequence, array $line, bool $fromBroker = false) => $streams->pushOut($channel, $generation, $sequence, $line, $fromBroker)];
    }

    $client = localRedisClient();

    return [
        new RedisBrokerStreams('streams_test'),
        fn (string $channel, int $generation, int $sequence, array $line, bool $fromBroker = false) => $client->xAdd($channel . ':out', '*', [
            'generation' => (string) $generation,
            'sequence' => (string) $sequence,
            'line' => json_encode($line),
            'origin' => $fromBroker ? 'broker' : '',
        ]),
    ];
}

test('channels are registered, filtered by prefix and unregistered', function (string $kind) {
    [$streams] = makeStreams($kind);

    $streams->register('threads:2');
    $streams->register('threads:1');
    $streams->register('runs:9');

    expect($streams->channels('threads:'))->toBe(['threads:1', 'threads:2'])
        ->and($streams->channels('runs:'))->toBe(['runs:9']);

    $streams->unregister('threads:1');
    expect($streams->channels('threads:'))->toBe(['threads:2']);

})->with(['fake', 'redis']);

test('a command is one JSON field on the inbound stream and returns its id', function () {
    $streams = new FakeBrokerStreams;

    $first = $streams->command('threads:1', ['type' => 'turn', 'uuid' => 'abc', 'text' => 'hi']);
    $streams->command('threads:1', ['type' => 'end']);

    expect($first)->not->toBe('')
        ->and($streams->commands('threads:1'))->toBe([['type' => 'turn', 'uuid' => 'abc', 'text' => 'hi'], ['type' => 'end']]);
});

test('a real redis stores a command under the command field', function () {
    $client = localRedisClient();

    (new RedisBrokerStreams('streams_test'))->command('threads:1', ['type' => 'steer', 'uuid' => 'u1']);
    $entries = $client->xRange('threads:1:in', '-', '+');

    expect(array_values($entries))->toHaveCount(1)
        ->and(json_decode(array_values($entries)[0]['command'], true))->toBe(['type' => 'steer', 'uuid' => 'u1']);
});

test('read returns runner and broker lines in order with their generation and sequence', function (string $kind) {
    [$streams, $push] = makeStreams($kind);
    $push('threads:1', 1, 1, ['type' => 'runner.started'], true);
    $push('threads:1', 1, 2, ['type' => 'assistant', 'uuid' => 'a']);

    $entries = $streams->read(['threads:1'], 'driver-a', 10, 10);

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->toBeInstanceOf(StreamEntry::class)
        ->and($entries[0]->channel)->toBe('threads:1')
        ->and($entries[0]->generation)->toBe(1)
        ->and($entries[0]->sequence)->toBe(1)
        ->and($entries[0]->line)->toBe(['type' => 'runner.started'])
        ->and($entries[0]->fromBroker)->toBeTrue()
        ->and($entries[1]->line['uuid'])->toBe('a')
        ->and($entries[1]->fromBroker)->toBeFalse()
        ->and($streams->read([], 'driver-a', 10, 10))->toBe([]);

})->with(['fake', 'redis']);

test('an unacknowledged entry is redelivered to its consumer before new ones', function (string $kind) {
    [$streams, $push] = makeStreams($kind);
    $push('threads:1', 1, 1, ['type' => 'one']);

    $first = $streams->read(['threads:1'], 'driver-a', 10, 1);
    $push('threads:1', 1, 2, ['type' => 'two']);
    $again = $streams->read(['threads:1'], 'driver-a', 10, 1);

    expect($again[0]->id)->toBe($first[0]->id);

    $streams->ack($first[0]);
    $next = $streams->read(['threads:1'], 'driver-a', 10, 1);

    expect($next[0]->line['type'])->toBe('two');

})->with(['fake', 'redis']);

test('trimBefore drops older outbound entries and keeps the given one', function (string $kind) {
    [$streams, $push] = makeStreams($kind);
    foreach ([1, 2, 3] as $sequence) {
        $push('threads:1', 1, $sequence, ['n' => $sequence]);
    }
    $entries = $streams->read(['threads:1'], 'driver-a', 10, 10);
    foreach ($entries as $entry) {
        $streams->ack($entry);
    }

    $streams->trimBefore('threads:1', $entries[1]->id);
    // A second consumer group member reading from the start sees what remains in the stream.
    $remaining = $streams instanceof FakeBrokerStreams
        ? $streams->outbound('threads:1')
        : array_map(fn (array $fields): int => (int) $fields['sequence'], array_values(Redis::connection('streams_test')->client()->xRange('threads:1:out', '-', '+')));

    expect(array_map(fn ($item) => $item instanceof StreamEntry ? $item->sequence : $item, $remaining))->toBe([2, 3]);

})->with(['fake', 'redis']);

test('failures are counted and a failing entry is dead-lettered out of the stream', function (string $kind) {
    [$streams, $push] = makeStreams($kind);
    $push('threads:1', 2, 7, ['type' => 'poison']);
    $entry = $streams->read(['threads:1'], 'driver-a', 10, 1)[0];

    expect($streams->recordFailure($entry))->toBe(1)
        ->and($streams->recordFailure($entry))->toBe(2);

    $streams->deadLetter($entry, 'handler exploded');

    expect($streams->read(['threads:1'], 'driver-a', 10, 10))->toBe([])
        ->and($streams->recordFailure($entry))->toBe(1);

})->with(['fake', 'redis']);

test('delete removes the channel streams', function (string $kind) {
    [$streams, $push] = makeStreams($kind);
    $push('threads:1', 1, 1, ['type' => 'one']);
    $streams->command('threads:1', ['type' => 'end']);

    $streams->delete('threads:1');

    expect($streams->read(['threads:1'], 'driver-a', 10, 10))->toBe([]);

})->with(['fake', 'redis']);

test('a real redis dead-letters a malformed entry on read', function () {
    $client = localRedisClient();
    $client->xAdd('threads:1:out', '*', ['generation' => 'x', 'sequence' => '1', 'line' => 'not json']);
    $client->xAdd('threads:1:out', '*', ['generation' => '1', 'sequence' => '2', 'line' => '{"type":"ok"}']);

    $entries = (new RedisBrokerStreams('streams_test'))->read(['threads:1'], 'driver-a', 10, 10);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->sequence)->toBe(2)
        ->and($client->xLen('threads:1:dead'))->toBe(1)
        ->and($client->xLen('threads:1:out'))->toBe(1);
});
