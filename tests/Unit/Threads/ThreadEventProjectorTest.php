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
        ['kind' => 'thinking', 'text' => 'hm'],
        ['kind' => 'text', 'text' => 'Hi'],
        ['kind' => 'tool_use', 'toolUseId' => 't1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
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
