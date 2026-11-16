<?php

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

    // The probed port can be taken before redis-server binds it, so a few ports are tried.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $started = startLocalRedisOnFreePort($binary);

        if ($started !== null) {
            return $started;
        }
    }

    return null;
}

/**
 * @return array{process: resource, port: int, directory: string}|null
 */
function startLocalRedisOnFreePort(string $binary): ?array
{
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

/**
 * Stops every redis-server a test started and drops the test connection.
 */
function stopStartedLocalRedis(): void
{
    foreach ($GLOBALS['startedRedisServers'] as $redis) {
        stopLocalRedis($redis);
    }
    $GLOBALS['startedRedisServers'] = [];
    Redis::purge('streams_test');
}

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
