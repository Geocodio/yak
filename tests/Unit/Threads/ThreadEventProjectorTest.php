<?php

use App\Services\Threads\ThreadEventProjector;

it('projects a tool result over 4000 characters as truncated', function () {
    $line = ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'is_error' => false, 'content' => str_repeat('x', 5000)]]]];

    $events = (new ThreadEventProjector)->project($line);

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('entry')
        ->and($events[0]['data']['role'])->toBe('tool_result')
        ->and($events[0]['data']['toolUseId'])->toBe('t1')
        ->and(strlen($events[0]['data']['text']))->toBe(4000)
        ->and($events[0]['data']['truncated'])->toBeTrue();
});

it('projects assistant blocks, init and result lines', function () {
    $projector = new ThreadEventProjector;

    $assistant = $projector->project(['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [
        ['type' => 'thinking', 'thinking' => 'hm'],
        ['type' => 'text', 'text' => 'Hi'],
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
    ]]]);

    expect($assistant[0]['data']['blocks'])->toBe([
        ['type' => 'thinking', 'text' => 'hm'],
        ['type' => 'text', 'text' => 'Hi', 'html' => "<p>Hi</p>\n"],
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
    ])
        ->and($projector->project(['type' => 'system', 'subtype' => 'init', 'session_id' => 's1', 'model' => 'opus'])[0]['data'])->toBe(['role' => 'system', 'subtype' => 'init', 'model' => 'opus', 'sessionId' => 's1'])
        ->and($projector->project(['type' => 'result', 'is_error' => true, 'result' => 'bad'])[0]['data']['isError'])->toBeTrue()
        ->and($projector->project(['type' => 'rate_limit_event']))->toBe([]);
});

it('resets the stream only for a synthetic user message while a block is open', function () {
    $line = ['type' => 'user', 'isSynthetic' => true, 'message' => ['content' => 'cut off']];
    $projector = new ThreadEventProjector;

    expect($projector->project($line, true))->toBe([['type' => 'stream.reset', 'data' => []]])
        ->and($projector->project($line, false))->toBe([]);
});

it('projects a prompt line with a uuid as a user entry and ignores one without', function () {
    $projector = new ThreadEventProjector;
    $line = ['type' => 'user', 'uuid' => 'u1', 'message' => ['content' => [['type' => 'text', 'text' => 'Fix it']]]];

    expect($projector->project($line)[0]['data'])->toBe(['role' => 'user', 'uuid' => 'u1', 'text' => 'Fix it', 'kind' => 'prompt'])
        ->and($projector->project(['type' => 'user', 'message' => ['content' => 'hi']]))->toBe([]);
});

it('extracts text, thinking and tool input deltas from stream events', function () {
    $projector = new ThreadEventProjector;
    $make = fn (array $delta, int $index = 2): array => ['type' => 'stream_event', 'message_id' => 'm1', 'event' => ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta]];

    expect($projector->delta($make(['type' => 'text_delta', 'text' => 'Hi'])))->toBe(['blockKey' => 'm1:2', 'kind' => 'text', 'text' => 'Hi'])
        ->and($projector->delta($make(['type' => 'thinking_delta', 'thinking' => 'hm']))['kind'])->toBe('thinking')
        ->and($projector->delta($make(['type' => 'input_json_delta', 'partial_json' => '{"a"']))['kind'])->toBe('tool_input')
        ->and($projector->delta($make(['type' => 'signature_delta'])))->toBeNull()
        ->and($projector->delta(['type' => 'assistant']))->toBeNull()
        ->and($projector->project($make(['type' => 'text_delta', 'text' => 'x'])))->toBe([]);
});

it('renders assistant text and result text as markdown html', function () {
    $projector = new ThreadEventProjector;

    $assistant = $projector->project(['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [['type' => 'text', 'text' => "## Done\n\nUsed `code` and **bold**"]]]]);
    $result = $projector->project(['type' => 'result', 'result' => '## Done']);

    expect($assistant[0]['data']['blocks'][0]['html'])->toContain('<h2>Done</h2>', '<code>code</code>', '<strong>bold</strong>')
        ->and($result[0]['data']['html'])->toContain('<h2>Done</h2>');
});

it('adds html to stored assistant entries that lack it and keeps existing html', function () {
    $data = (new ThreadEventProjector)->withHtml(['role' => 'assistant', 'blocks' => [
        ['type' => 'text', 'text' => '## Old'],
        ['type' => 'text', 'text' => 'kept', 'html' => '<p>kept</p>'],
        ['type' => 'thinking', 'text' => 'hm'],
    ]]);

    expect($data['blocks'][0]['html'])->toContain('<h2>Old</h2>')
        ->and($data['blocks'][1]['html'])->toBe('<p>kept</p>')
        ->and($data['blocks'][2])->toBe(['type' => 'thinking', 'text' => 'hm']);
});
