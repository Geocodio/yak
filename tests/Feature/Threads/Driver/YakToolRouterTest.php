<?php

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Models\Thread;
use App\Services\Threads\DefaultYakToolRouter;
use App\Services\Threads\YakToolRouter;

it('parses a tool call line', function () {
    $call = YakToolCall::fromLine(['type' => 'yak.tool_call', 'call_id' => 'c1', 'tool' => 'list_threads', 'input' => ['a' => 1]]);

    expect($call->callId)->toBe('c1')
        ->and($call->tool)->toBe('list_threads')
        ->and($call->input)->toBe(['a' => 1]);
});

it('rejects a line without a call id', function (array $line) {
    expect(YakToolCall::fromLine($line))->toBeNull();
})->with([
    'no call id' => [['type' => 'yak.tool_call', 'tool' => 'list_threads']],
    'no tool' => [['type' => 'yak.tool_call', 'call_id' => 'c1']],
    'other type' => [['type' => 'assistant', 'call_id' => 'c1', 'tool' => 'x']],
]);

it('answers not available by default', function () {
    $router = app(YakToolRouter::class);
    $result = $router->handle(Thread::factory()->create(), new YakToolCall('c1', 'spawn_thread', []));

    expect($router)->toBeInstanceOf(DefaultYakToolRouter::class)
        ->and($result)->toEqual(new YakToolResult(true, 'The spawn_thread tool is not available yet.'));
});
