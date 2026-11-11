<?php

use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
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

beforeEach(function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
});

afterEach(function () {
    $redis = $GLOBALS['realtimeRedis'];

    if ($redis !== null) {
        proc_terminate($redis['process']);
        proc_close($redis['process']);
        array_map('unlink', glob($redis['directory'] . '/*') ?: []);
        @rmdir($redis['directory']);
        $GLOBALS['realtimeRedis'] = null;
    }

    Redis::purge('threads');
});

it('allocates strictly increasing sequences per thread', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $first = Thread::factory()->create();
    $second = Thread::factory()->create();
    $publisher = app(ThreadEventPublisher::class);

    $sequences = ['first' => [], 'second' => []];

    foreach ([$first, $second, $first, $second, $first] as $thread) {
        $key = $thread->is($first) ? 'first' : 'second';
        $sequences[$key][] = $publisher->append($thread->id, 'entry', ['role' => 'user'])['sequence'];
    }

    expect($sequences['first'])->toBe([1, 2, 3])
        ->and($sequences['second'])->toBe([1, 2])
        ->and(DB::table('threads')->where('id', $first->id)->value('event_sequence'))->toBe(3);
});

it('stores entries but not deltas', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();
    $publisher = app(ThreadEventPublisher::class);

    $publisher->append($thread->id, 'entry', ['role' => 'user']);
    $publisher->append($thread->id, 'delta', ['blockKey' => 'a', 'kind' => 'text', 'text' => 'hi'], isStored: false);

    expect(DB::table('thread_events')->where('thread_id', $thread->id)->pluck('type')->all())->toBe(['entry']);

    $window = app(ThreadEventWindow::class)->since($thread->id, 0, 100);

    expect(array_column($window, 'type'))->toBe(['entry', 'delta'])
        ->and(array_column($window, 'sequence'))->toBe([1, 2]);

    app(ThreadEventWindow::class)->forget($thread->id);
});

it('does not broadcast an event whose transaction rolled back', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();
    $publisher = app(ThreadEventPublisher::class);

    try {
        DB::transaction(function () use ($publisher, $thread) {
            $publisher->record($thread->id, 'entry', ['role' => 'user']);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    $publisher->flush();

    Event::assertNotDispatched(ThreadEventBroadcast::class);
    expect(DB::table('thread_events')->count())->toBe(0);
});

it('broadcasts a committed event once', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();

    app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['role' => 'user']);

    Event::assertDispatchedTimes(ThreadEventBroadcast::class, 1);
    app(ThreadEventWindow::class)->forget($thread->id);
});

it('reports and survives a failed broadcast', function () {
    Exceptions::fake();
    Event::listen(ThreadEventBroadcast::class, fn () => throw new RuntimeException('reverb down'));
    $thread = Thread::factory()->create();

    $event = app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['role' => 'user']);

    expect($event['sequence'])->toBe(1)
        ->and(DB::table('thread_events')->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'reverb down');
    app(ThreadEventWindow::class)->forget($thread->id);
});

it('records the thread state without personal data', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();

    $event = app(ThreadEventPublisher::class)->appendThreadState($thread->id);

    expect($event['type'])->toBe('thread')
        ->and($event['data'])->toBe(['status' => 'open', 'sandboxState' => 'none', 'runnerState' => 'none', 'blockedReason' => null]);
    app(ThreadEventWindow::class)->forget($thread->id);
});

it('window returns null when it no longer covers after', function () {
    $window = app(ThreadEventWindow::class);
    $at = now()->toImmutable();

    expect($window->since(7, 0, 10))->toBeNull();

    $window->push(7, 1, 'entry', ['a' => 1], $at);
    $window->push(7, 2, 'entry', ['a' => 2], $at);
    expect($window->since(7, 0, 10))->toHaveCount(2)
        ->and($window->since(7, 1, 10))->toHaveCount(1);

    $this->travel(11)->minutes();
    $window->push(7, 3, 'entry', ['a' => 3], now()->toImmutable());

    expect($window->since(7, 0, 10))->toBeNull()
        ->and($window->since(7, 2, 10))->toHaveCount(1);

    $window->forget(7);
});
