<?php

use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__ . '/RealtimeRedis.php';

beforeEach(function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
});

afterEach(fn () => stopThrowawayRedis());

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
