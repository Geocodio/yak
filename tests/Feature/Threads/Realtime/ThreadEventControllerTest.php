<?php

use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/RealtimeRedis.php';

beforeEach(function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    Event::fake([ThreadEventBroadcast::class]);
});

afterEach(fn () => stopThrowawayRedis());

/**
 * @return array{Thread, ThreadEventPublisher}
 */
function threadWithEvents(): array
{
    $thread = Thread::factory()->create();
    $publisher = app(ThreadEventPublisher::class);
    $publisher->append($thread->id, 'entry', ['role' => 'user', 'text' => 'one']);
    $publisher->append($thread->id, 'delta', ['blockKey' => 'a:0', 'kind' => 'text', 'text' => 'hi'], isStored: false);
    $publisher->append($thread->id, 'entry', ['role' => 'user', 'text' => 'three']);

    return [$thread, $publisher];
}

it('returns events after a sequence from the redis window', function () {
    [$thread] = threadWithEvents();

    $response = $this->actingAs(User::factory()->create())->getJson(route('threads.events.index', ['thread' => $thread, 'after' => 1]))->assertOk();

    expect($response->json('source'))->toBe('window')
        ->and($response->json('latestSequence'))->toBe(3)
        ->and(array_column($response->json('events'), 'type'))->toBe(['delta', 'entry']);
});

it('falls back to the database when the window is older than after', function () {
    [$thread] = threadWithEvents();
    $this->travel(11)->minutes();
    app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['role' => 'user', 'text' => 'four']);

    $response = $this->actingAs(User::factory()->create())->getJson(route('threads.events.index', ['thread' => $thread, 'after' => 0]))->assertOk();

    expect($response->json('source'))->toBe('database')
        ->and(array_column($response->json('events'), 'sequence'))->toBe([1, 3, 4])
        ->and(array_column($response->json('events'), 'type'))->not->toContain('delta');
});

it('returns the full stored event for a pointer', function () {
    $thread = Thread::factory()->create();
    $text = str_repeat('x', 9000);
    $event = app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't1', 'isError' => false, 'text' => $text]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.events.show', ['thread' => $thread, 'sequence' => $event['sequence']]))
        ->assertOk()
        ->assertJsonPath('data.text', $text)
        ->assertJsonPath('sequence', 1);

    $this->actingAs(User::factory()->create())->getJson(route('threads.events.show', ['thread' => $thread, 'sequence' => 99]))->assertNotFound();
});

it('answers 404 for a private thread exactly like a missing one', function (string $who) {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $viewer = User::factory()->create($who === 'admin' ? ['email' => 'admin@example.com'] : []);

    $this->actingAs($viewer);
    $hidden = $this->getJson(route('threads.events.index', ['thread' => $thread->id]));
    $missing = $this->getJson(route('threads.events.index', ['thread' => 999999]));
    $hiddenEvent = $this->getJson(route('threads.events.show', ['thread' => $thread->id, 'sequence' => 1]));

    $hidden->assertNotFound();
    $hiddenEvent->assertNotFound();
    expect(preg_replace('/\d+/', 'N', (string) $hidden->json('message')))->toBe(preg_replace('/\d+/', 'N', (string) $missing->json('message')))
        ->and($hidden->json('exception'))->toBe($missing->json('exception'));

    $this->actingAs($owner)->getJson(route('threads.events.index', ['thread' => $thread->id]))->assertOk();
})->with(['second user' => 'user', 'admin' => 'admin']);

it('requires login', function () {
    $thread = Thread::factory()->create();

    $this->getJson(route('threads.events.index', ['thread' => $thread->id]))->assertUnauthorized();
});
