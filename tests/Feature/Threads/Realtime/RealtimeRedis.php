<?php

use Illuminate\Support\Facades\Redis;

/** @var array{process: resource, directory: string}|null */
$GLOBALS['realtimeRedis'] = null;

/**
 * Points the `threads` connection at a throwaway redis-server, or skips when none is installed.
 */
function useThrowawayRedis(): void
{
    $binary = trim((string) shell_exec('command -v redis-server'));

    if ($binary === '') {
        test()->markTestSkipped('redis-server is not installed.');
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $directory = sys_get_temp_dir() . '/yak-realtime-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);

    $process = proc_open(
        [$binary, '--port', (string) $port, '--bind', '127.0.0.1', '--dir', $directory, '--save', '', '--appendonly', 'no'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    while (($line = fgets($pipes[1])) !== false) {
        if (str_contains($line, 'Ready to accept connections')) {
            break;
        }
    }

    $GLOBALS['realtimeRedis'] = ['process' => $process, 'directory' => $directory];
    config(['database.redis.threads' => ['host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'options' => ['prefix' => '']]]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
}

/**
 * Stops the throwaway redis-server started by useThrowawayRedis().
 */
function stopThrowawayRedis(): void
{
    $redis = $GLOBALS['realtimeRedis'];

    if ($redis !== null) {
        proc_terminate($redis['process']);
        proc_close($redis['process']);
        array_map('unlink', glob($redis['directory'] . '/*') ?: []);
        @rmdir($redis['directory']);
        $GLOBALS['realtimeRedis'] = null;
    }

    Redis::purge('threads');
}
