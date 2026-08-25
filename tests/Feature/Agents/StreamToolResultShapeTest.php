<?php

use App\Agents\StreamEventHandler;
use App\Models\TaskLog;
use App\Models\YakTask;

/**
 * The Claude CLI never emits a top-level `tool_result` event. Results
 * arrive as tool_result blocks nested in a `user` message, mirroring how
 * tool_use blocks arrive nested in an `assistant` message. Shapes here are
 * copied from a real session transcript pulled off the production host.
 */
function assistantToolUse(string $id, string $command): array
{
    return [
        'type' => 'assistant',
        'message' => [
            'content' => [
                ['type' => 'tool_use', 'id' => $id, 'name' => 'Bash', 'input' => ['command' => $command]],
            ],
        ],
    ];
}

function userToolResult(string $id, string|array $content, bool $isError = false): array
{
    return [
        'type' => 'user',
        'message' => [
            'content' => [
                ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => $content, 'is_error' => $isError],
            ],
        ],
    ];
}

it('captures output from a tool_result nested in a user message', function () {
    $task = YakTask::factory()->create();
    $handler = new StreamEventHandler($task);

    $handler->handle(assistantToolUse('toolu_01A', 'git log --oneline -1'));
    $handler->handle(userToolResult('toolu_01A', "179c023aa Explain duplicate spam entries\n"));

    $log = TaskLog::where('yak_task_id', $task->id)->latest('id')->first();

    expect($log->metadata['output'])->toContain('179c023aa')
        ->and($log->metadata['is_error'])->toBeFalse()
        ->and($log->message)->toContain('exit 0');
});

it('handles tool_result content given as a list of blocks', function () {
    $task = YakTask::factory()->create();
    $handler = new StreamEventHandler($task);

    $handler->handle(assistantToolUse('toolu_01B', 'ls'));
    $handler->handle(userToolResult('toolu_01B', [
        ['type' => 'text', 'text' => 'README.md'],
        ['type' => 'text', 'text' => 'composer.json'],
    ]));

    $log = TaskLog::where('yak_task_id', $task->id)->latest('id')->first();

    expect($log->metadata['output'])->toContain('README.md')
        ->and($log->metadata['output'])->toContain('composer.json');
});

it('marks an errored tool_result', function () {
    $task = YakTask::factory()->create();
    $handler = new StreamEventHandler($task);

    $handler->handle(assistantToolUse('toolu_01C', 'exit 2'));
    $handler->handle(userToolResult('toolu_01C', 'boom', isError: true));

    $log = TaskLog::where('yak_task_id', $task->id)->latest('id')->first();

    expect($log->metadata['is_error'])->toBeTrue()
        ->and($log->level)->toBe('warning');
});

it('records how long the tool call took', function () {
    $task = YakTask::factory()->create();
    $handler = new StreamEventHandler($task);

    $handler->handle(assistantToolUse('toolu_01D', 'sleep 1'));
    $handler->handle(userToolResult('toolu_01D', 'ok'));

    $log = TaskLog::where('yak_task_id', $task->id)->latest('id')->first();

    expect($log->metadata)->toHaveKey('duration_ms')
        ->and($log->metadata['duration_ms'])->toBeInt()
        ->and($log->metadata['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('attaches each result to its own call when two are outstanding', function () {
    $task = YakTask::factory()->create();
    $handler = new StreamEventHandler($task);

    $handler->handle(assistantToolUse('toolu_01E', 'echo first'));
    $handler->handle(assistantToolUse('toolu_01F', 'echo second'));

    // Results can come back in either order; correlate by tool_use_id.
    $handler->handle(userToolResult('toolu_01F', 'second output'));
    $handler->handle(userToolResult('toolu_01E', 'first output'));

    $logs = TaskLog::where('yak_task_id', $task->id)->orderBy('id')->get();

    expect($logs[0]->metadata['output'])->toContain('first output')
        ->and($logs[1]->metadata['output'])->toContain('second output');
});
