<?php

use App\Events\ThreadActivity;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('numbers events per thread without gaps', function () {
    $publisher = app(ThreadEventPublisher::class);
    $first = Thread::factory()->create();
    $second = Thread::factory()->create();

    foreach (range(1, 3) as $index) {
        $publisher->append($first->id, 'entry', ['n' => $index]);
        $publisher->append($second->id, 'entry', ['n' => $index]);
    }

    expect(ThreadEvent::where('thread_id', $first->id)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and(ThreadEvent::where('thread_id', $second->id)->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($first->fresh()->event_sequence)->toBe(3);
});

it('broadcasts only after the transaction commits', function () {
    Event::fake([ThreadActivity::class]);
    $publisher = app(ThreadEventPublisher::class);
    $thread = Thread::factory()->create();

    try {
        DB::transaction(function () use ($publisher, $thread) {
            $publisher->record(Thread::query()->lockForUpdate()->find($thread->id), 'entry', ['a' => 1]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // The transaction failed, so the queued event is dropped without a flush.
    }

    Event::assertNotDispatched(ThreadActivity::class);
    expect(ThreadEvent::count())->toBe(0)
        ->and($thread->fresh()->event_sequence)->toBe(0);

    $publisher->append($thread->id, 'entry', ['a' => 2]);

    Event::assertDispatchedTimes(ThreadActivity::class, 1);
});

it('sends a pointer when escaping pushes a payload over 8 KB on the wire', function () {
    $thread = Thread::factory()->create();
    $event = app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['text' => str_repeat('"', 2000)]);

    expect(strlen((string) json_encode(['sequence' => 1, 'type' => 'entry', 'data' => $event->payload])))->toBeLessThan(ThreadActivity::MAX_PAYLOAD_BYTES)
        ->and((new ThreadActivity($event))->broadcastWith())->toBe(['sequence' => 1, 'type' => 'entry', 'pointer' => true]);
});

it('sends a pointer for a payload over 8 KB', function () {
    $thread = Thread::factory()->create();
    $event = app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['text' => str_repeat('x', 9000)]);
    $small = app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['text' => 'hi']);

    expect((new ThreadActivity($event))->broadcastWith())->toBe(['sequence' => 1, 'type' => 'entry', 'pointer' => true])
        ->and((new ThreadActivity($small))->broadcastWith())->toBe(['sequence' => 2, 'type' => 'entry', 'data' => ['text' => 'hi']])
        ->and((new ThreadActivity($small))->broadcastAs())->toBe('thread.event');
});
