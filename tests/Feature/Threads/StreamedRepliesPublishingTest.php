<?php

use App\Events\ThreadActivity;
use App\Events\ThreadEventBroadcast;
use App\Listeners\PublishThreadActivity;
use App\Models\Thread;
use Illuminate\Support\Facades\Event;

/**
 * @return list<ThreadEventBroadcast>
 */
function publishFixture(string $name): array
{
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();
    $listener = app(PublishThreadActivity::class);

    $lines = array_slice(file(base_path("tests/Fixtures/runner-streams/{$name}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1);

    foreach ($lines as $sequence => $json) {
        $line = json_decode($json, true);
        unset($line['_ms']);
        $listener->handle(new ThreadActivity($thread, 1, $sequence, $line));
    }

    return Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $arguments): ThreadEventBroadcast => $arguments[0])->all();
}

it('publishes deltas whose text and key match the final entry blocks', function () {
    $events = publishFixture('streamed-reply');
    $deltas = array_values(array_filter($events, fn ($event) => $event->type === 'delta'));
    $entries = array_values(array_filter($events, fn ($event) => $event->type === 'entry' && ($event->data['role'] ?? null) === 'assistant' && isset($event->data['blockIndex'])));

    expect($deltas)->not->toBeEmpty()->and($entries)->not->toBeEmpty();

    $textByKey = [];
    $nextOffset = [];

    foreach ($deltas as $delta) {
        $key = $delta->data['blockKey'];
        expect($delta->data['offset'])->toBe($nextOffset[$key] ?? 0);
        $nextOffset[$key] = $delta->data['offset'] + intdiv(strlen(mb_convert_encoding($delta->data['text'], 'UTF-16LE', 'UTF-8')), 2);
        $textByKey[$key] = ($textByKey[$key] ?? '') . $delta->data['text'];
    }

    foreach ($entries as $entry) {
        $key = $entry->data['messageId'] . ':' . $entry->data['blockIndex'];
        $block = $entry->data['blocks'][0];

        expect($textByKey)->toHaveKey($key)
            ->and($entry->data['startedAt'])->toBeString();

        if ($block['type'] === 'text') {
            expect($textByKey[$key])->toBe($block['text']);
        }
    }
});

it('publishes deltas only for the main reply while an agent runs', function () {
    $events = publishFixture('streamed-subagent');
    $entryKeys = collect($events)
        ->filter(fn ($event) => $event->type === 'entry' && ($event->data['role'] ?? null) === 'assistant' && isset($event->data['blockIndex']) && ! isset($event->data['agentId']))
        ->map(fn ($event) => $event->data['messageId'] . ':' . $event->data['blockIndex']);
    $deltaKeys = collect($events)->filter(fn ($event) => $event->type === 'delta')->map(fn ($event) => $event->data['blockKey'])->unique();

    expect($deltaKeys)->not->toBeEmpty()
        ->and($deltaKeys->diff($entryKeys)->all())->toBe([]);
});

it('ignores a stream line carrying a parent tool use id', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();

    app(PublishThreadActivity::class)->handle(new ThreadActivity($thread, 1, 1, [
        'type' => 'stream_event', 'parent_tool_use_id' => 'toolu_1', 'api_message_id' => 'msg_1',
        'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'x']],
    ]));
    app(PublishThreadActivity::class)->handle(new ThreadActivity($thread, 1, 2, ['type' => 'result', 'result' => 'done']));

    expect(Event::dispatched(ThreadEventBroadcast::class)->filter(fn (array $a) => $a[0]->type === 'delta'))->toHaveCount(0);
});
