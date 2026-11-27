<?php

use App\Events\ThreadActivity;
use App\Events\ThreadEventBroadcast;
use App\Listeners\PublishThreadActivity;
use App\Models\Thread;
use App\Services\Threads\DeltaBatcher;
use Illuminate\Support\Facades\Event;

/**
 * @return list<ThreadEventBroadcast>
 */
function publishStreamedFixture(string $name): array
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
    $events = publishStreamedFixture('streamed-reply');
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

        $opening = collect($deltas)->first(fn ($delta) => $delta->data['blockKey'] === $key && $delta->data['offset'] === 0);

        expect($textByKey)->toHaveKey($key)
            ->and($entry->data['startedAt'])->toBeString()
            ->and($opening->data['startedAt'])->toBe($entry->data['startedAt']);

        if ($block['type'] === 'text') {
            expect($textByKey[$key])->toBe($block['text']);
        }
    }
});

it('publishes deltas only for the main reply while an agent runs', function () {
    $events = publishStreamedFixture('streamed-subagent');
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

function streamedLine(string $type, array $extra = []): array
{
    return ['type' => 'stream_event', 'api_message_id' => 'msg_1', 'event' => ['type' => $type, 'index' => 0] + $extra];
}

it('leaves no stream state after a result or a runner exit', function (array $endLine) {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();
    $listener = app(PublishThreadActivity::class);

    foreach ([streamedLine('content_block_start'), streamedLine('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => 'hi']]), $endLine] as $sequence => $line) {
        $listener->handle(new ThreadActivity($thread, 1, $sequence, $line));
    }

    expect(app(DeltaBatcher::class)->hasStateFor($thread->id))->toBeFalse()
        ->and(Event::dispatched(ThreadEventBroadcast::class)->filter(fn (array $a) => $a[0]->type === 'delta'))->toHaveCount(1);
})->with([
    'result' => [['type' => 'result', 'result' => 'done']],
    'runner exit' => [['type' => 'broker', 'event' => 'runner_exited']],
]);

it('omits blockIndex and startedAt when nothing streamed', function () {
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();
    $listener = app(PublishThreadActivity::class);

    $listener->handle(new ThreadActivity($thread, 1, 1, streamedLine('content_block_start')));
    $listener->handle(new ThreadActivity($thread, 1, 2, ['type' => 'assistant', 'message' => ['id' => 'msg_1', 'content' => [['type' => 'text', 'text' => 'hi']]]]));

    $entry = Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $a) => $a[0])->first(fn ($e) => $e->type === 'entry');

    expect($entry->data)->not->toHaveKeys(['blockIndex', 'startedAt']);
});

/**
 * @param  list<array<string, mixed>>  $lines
 * @return list<ThreadEventBroadcast>
 */
function publishStreamedLines(array $lines): array
{
    Event::fake([ThreadEventBroadcast::class]);
    $thread = Thread::factory()->create();
    $listener = app(PublishThreadActivity::class);

    foreach ($lines as $sequence => $line) {
        $listener->handle(new ThreadActivity($thread, 1, $sequence, $line));
    }

    return Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $arguments): ThreadEventBroadcast => $arguments[0])->all();
}

function streamedDeltaLine(string $text): array
{
    return streamedLine('content_block_delta', ['delta' => ['type' => 'text_delta', 'text' => $text]]);
}

it('names the last streamed block when an interrupt arrives', function () {
    $events = publishStreamedFixture('streamed-interrupt');
    $cuts = array_values(array_filter($events, fn ($event) => $event->type === 'block.cut'));
    $entry = collect($events)->last(fn ($event) => $event->type === 'entry' && isset($event->data['blockIndex']));

    expect($cuts)->toHaveCount(1)
        ->and($cuts[0]->data['blockKey'])->toBe($entry->data['messageId'] . ':' . $entry->data['blockIndex'])
        ->and($cuts[0]->data['reason'])->toBe('stopped')
        ->and(array_filter($events, fn ($event) => ($event->data['cutOff'] ?? false) === true))->toBe([]);
});

it('stores the streamed text of a block that never got its assistant line', function (array $endLine) {
    $events = publishStreamedLines([streamedLine('content_block_start'), streamedDeltaLine('Hello '), streamedDeltaLine('wor'), $endLine]);
    $cutOff = array_values(array_filter($events, fn ($event) => $event->type === 'entry' && ($event->data['cutOff'] ?? false) === true));

    expect($cutOff)->toHaveCount(1)
        ->and($cutOff[0]->data['role'])->toBe('assistant')
        ->and($cutOff[0]->data['messageId'])->toBe('msg_1')
        ->and($cutOff[0]->data['blockIndex'])->toBe(0)
        ->and($cutOff[0]->data['cutReason'])->toBe('restarted')
        ->and($cutOff[0]->data['startedAt'])->toBeString()
        ->and($cutOff[0]->data['blocks'][0]['text'])->toBe('Hello wor')
        ->and($cutOff[0]->data['blocks'][0]['html'])->toContain('Hello wor');
})->with([
    'runner exit' => [['type' => 'broker', 'event' => 'runner_exited']],
    'result' => [['type' => 'result', 'result' => 'done']],
    'stream reset' => [['type' => 'user', 'isSynthetic' => true, 'message' => ['content' => 'x']]],
]);

it('never stores a block twice when its assistant line arrived', function () {
    $events = publishStreamedLines([
        streamedLine('content_block_start'), streamedDeltaLine('hi'),
        ['type' => 'assistant', 'message' => ['id' => 'msg_1', 'content' => [['type' => 'text', 'text' => 'hi']]]],
        ['type' => 'result', 'result' => 'done'],
    ]);
    $stored = array_filter($events, fn ($event) => $event->type === 'entry' && ($event->data['role'] ?? null) === 'assistant');

    expect($stored)->toHaveCount(1)
        ->and(array_filter($events, fn ($event) => ($event->data['cutOff'] ?? false) === true))->toBe([]);
});

it('redacts a secret split across two deltas in the cut-off entry', function () {
    $events = publishStreamedLines([
        streamedLine('content_block_start'), streamedDeltaLine('key sk-ant-abc12'), streamedDeltaLine('3456789xyz done'),
        ['type' => 'broker', 'event' => 'runner_exited'],
    ]);
    $cutOff = array_values(array_filter($events, fn ($event) => $event->type === 'entry' && ($event->data['cutOff'] ?? false) === true));

    expect($cutOff)->toHaveCount(1)
        ->and($cutOff[0]->data['blocks'][0]['text'])->not->toContain('sk-ant')
        ->and($cutOff[0]->data['blocks'][0]['text'])->toContain('[redacted]');
});

it('stores a later block of a message after the first block finished', function () {
    $block = fn (string $type, int $index, array $extra = []) => ['type' => 'stream_event', 'api_message_id' => 'msg', 'event' => ['type' => $type, 'index' => $index] + $extra];
    $delta = fn (int $index, string $text) => $block('content_block_delta', $index, ['delta' => ['type' => 'text_delta', 'text' => $text]]);

    $events = publishStreamedLines([
        $block('content_block_start', 0), $delta(0, 'one'),
        ['type' => 'assistant', 'message' => ['id' => 'msg', 'content' => [['type' => 'text', 'text' => 'one']]]],
        $block('content_block_stop', 0), $block('content_block_start', 1), $delta(1, 'two'),
        ['type' => 'broker', 'event' => 'runner_exited'],
    ]);
    $cutOff = array_values(array_filter($events, fn ($event) => $event->type === 'entry' && ($event->data['cutOff'] ?? false) === true));

    expect($cutOff)->toHaveCount(1)
        ->and($cutOff[0]->data['messageId'] . ':' . $cutOff[0]->data['blockIndex'])->toBe('msg:1');
});
