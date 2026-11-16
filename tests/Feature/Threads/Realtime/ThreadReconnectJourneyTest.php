<?php

use App\Events\ThreadActivity;
use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/RealtimeRedis.php';

beforeEach(function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    Event::fake([ThreadEventBroadcast::class]);
});

afterEach(fn () => stopThrowawayRedis());

/**
 * @return list<ThreadEventBroadcast>
 */
function broadcasts(): array
{
    return Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $arguments): ThreadEventBroadcast => $arguments[0])->all();
}

function journeyLine(Thread $thread, array $line, int &$counter): void
{
    event(new ThreadActivity($thread, 1, ++$counter, $line));
}

function journeyPrompt(string $uuid, string $text): array
{
    return ['type' => 'user', 'uuid' => $uuid, 'message' => ['content' => $text]];
}

function journeyDelta(string $text): array
{
    return ['type' => 'stream_event', 'message_id' => 'm1', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]];
}

it('delivers live events, then catches a reconnecting client up exactly once', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $counter = 0;

    foreach (['one', 'two', 'three', 'four'] as $index => $text) {
        journeyLine($thread, journeyPrompt("u{$index}", $text), $counter);
    }

    $seenBeforeDrop = collect(broadcasts())->pluck('sequence')->all();
    expect($seenBeforeDrop)->toBe([1, 2, 3, 4]);

    // The socket is down: sequences 5 and up are published but never seen.
    journeyLine($thread, journeyDelta('Hel'), $counter);
    $this->travel(60)->milliseconds();
    journeyLine($thread, journeyPrompt('u5', 'five'), $counter);
    journeyLine($thread, ['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [['type' => 'text', 'text' => str_repeat('x', 9000)]]]], $counter);
    journeyLine($thread, journeyPrompt('u7', 'seven'), $counter);

    $missed = collect(broadcasts())->filter(fn (ThreadEventBroadcast $event): bool => $event->sequence > 4);
    $pointer = $missed->first(fn (ThreadEventBroadcast $event): bool => ($event->broadcastWith()['pointer'] ?? false) === true);

    expect($pointer)->not->toBeNull();

    $this->actingAs($owner);
    $catchUp = $this->getJson(route('threads.events.index', ['thread' => $thread, 'after' => 4]))->assertOk();
    $sequences = array_column($catchUp->json('events'), 'sequence');

    expect($sequences)->toBe(collect($sequences)->sort()->values()->all())
        ->and(min($sequences))->toBeGreaterThan(4)
        ->and($sequences)->toBe(array_values(array_unique($sequences)))
        ->and($sequences)->toContain($pointer->sequence)
        ->and($catchUp->json('latestSequence'))->toBe($missed->max('sequence'))
        ->and($catchUp->json('source'))->toBe('window');

    $this->getJson(route('threads.events.show', ['thread' => $thread, 'sequence' => $pointer->sequence]))
        ->assertOk()
        ->assertJsonPath('sequence', $pointer->sequence)
        ->assertJsonPath('data.role', 'assistant');

    $this->getJson(route('threads.events.index', ['thread' => $thread, 'after' => $catchUp->json('latestSequence')]))
        ->assertOk()
        ->assertJsonPath('events', []);

    $this->travel(11)->minutes();
    journeyLine($thread, journeyPrompt('u8', 'eight'), $counter);

    $afterWindow = $this->getJson(route('threads.events.index', ['thread' => $thread, 'after' => 4]))->assertOk();

    expect($afterWindow->json('source'))->toBe('database')
        ->and(array_column($afterWindow->json('events'), 'type'))->not->toContain('delta')
        ->and(array_column($afterWindow->json('events'), 'type'))->each->toBe('entry');
});
