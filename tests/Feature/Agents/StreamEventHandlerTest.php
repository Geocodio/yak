<?php

use App\Agents\StreamEventHandler;
use App\Models\TaskLog;
use App\Models\YakTask;

beforeEach(function () {
    $this->task = YakTask::factory()->running()->create();
    $this->handler = new StreamEventHandler($this->task);
});

test('handles assistant text events', function () {
    $this->handler->handle([
        'type' => 'assistant',
        'message' => [
            'content' => [
                ['type' => 'text', 'text' => 'I will read the README first.'],
            ],
        ],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log)->not->toBeNull();
    expect($log->message)->toBe('I will read the README first.');
    expect($log->metadata['type'])->toBe('assistant');
});

test('handles bash tool use events', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Bash',
        'input' => [
            'command' => 'npm install',
            'description' => 'Install dependencies',
        ],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toBe('⚡ Install dependencies');
    expect($log->metadata['type'])->toBe('tool_use');
    expect($log->metadata['tool'])->toBe('Bash');
});

test('handles bash tool use with command when no description', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Bash',
        'input' => ['command' => 'ls -la'],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toBe('⚡ `ls -la`');
});

test('handles read tool use', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Read',
        'input' => ['file_path' => '/app/README.md'],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toBe('📄 Reading `/app/README.md`');
});

test('handles edit tool use', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Edit',
        'input' => ['file_path' => '/app/config.php'],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toBe('✏️ Editing `/app/config.php`');
});

test('handles grep tool use', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Grep',
        'input' => ['pattern' => 'TODO'],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toBe('🔍 Searching for `TODO`');
});

test('handles tool result and updates pending log', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Bash',
        'input' => ['command' => 'echo hello'],
    ]);

    $this->handler->handle([
        'type' => 'tool_result',
        'content' => 'hello',
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toContain('→ exit 0');
    expect($log->metadata['output'])->toBe('hello');
    expect($log->metadata['is_error'])->toBeFalse();
});

test('handles tool result with error', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Bash',
        'input' => ['command' => 'false'],
    ]);

    $this->handler->handle([
        'type' => 'tool_result',
        'content' => 'Exit code 1',
        'is_error' => true,
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toContain('→ exit 1');
    expect($log->level)->toBe('warning');
    expect($log->metadata['is_error'])->toBeTrue();
});

test('handles result event', function () {
    $resultEvent = [
        'type' => 'result',
        'session_id' => 'abc123',
        'result' => 'All done',
        'is_error' => false,
        'cost_usd' => 0.15,
        'num_turns' => 5,
        'duration_ms' => 30000,
    ];

    $this->handler->handle($resultEvent);

    expect($this->handler->getResultEvent())->toBe($resultEvent);
});

test('truncates long assistant messages', function () {
    $longText = str_repeat('a', 600);

    $this->handler->handle([
        'type' => 'assistant',
        'message' => [
            'content' => [
                ['type' => 'text', 'text' => $longText],
            ],
        ],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect(mb_strlen($log->message))->toBeLessThan(510);
    expect($log->message)->toEndWith('…');
});

test('truncates long bash output', function () {
    $lines = [];
    for ($i = 0; $i < 50; $i++) {
        $lines[] = "line {$i}";
    }
    $output = implode("\n", $lines);

    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'Bash',
        'input' => ['command' => 'big-output'],
    ]);

    $this->handler->handle([
        'type' => 'tool_result',
        'content' => $output,
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->metadata['output'])->toContain('lines hidden');
    expect($log->metadata['output_lines'])->toBe(50);
});

test('ignores empty assistant messages', function () {
    $this->handler->handle([
        'type' => 'assistant',
        'message' => [
            'content' => [
                ['type' => 'text', 'text' => ''],
            ],
        ],
    ]);

    expect(TaskLog::where('yak_task_id', $this->task->id)->count())->toBe(0);
});

test('ignores unknown event types', function () {
    $this->handler->handle([
        'type' => 'system',
        'subtype' => 'init',
    ]);

    expect(TaskLog::where('yak_task_id', $this->task->id)->count())->toBe(0);
});

test('handles mcp tool calls', function () {
    $this->handler->handle([
        'type' => 'tool_use',
        'tool' => 'mcp__context7__query-docs',
        'input' => ['query' => 'livewire'],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toContain('MCP:');
    expect($log->metadata['tool'])->toBe('mcp__context7__query-docs');
});

test('extracts tool_use blocks from assistant messages', function () {
    $this->handler->handle([
        'type' => 'assistant',
        'message' => [
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'Glob',
                    'input' => ['pattern' => 'src/**'],
                    'id' => 'toolu_abc123',
                ],
            ],
        ],
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log)->not->toBeNull();
    expect($log->message)->toBe('📂 Finding files: `src/**`');
    expect($log->metadata['type'])->toBe('tool_use');
    expect($log->metadata['tool'])->toBe('Glob');
});

test('extracts tool_use alongside text from assistant messages', function () {
    $this->handler->handle([
        'type' => 'assistant',
        'message' => [
            'content' => [
                ['type' => 'text', 'text' => 'Let me check the files.'],
                [
                    'type' => 'tool_use',
                    'name' => 'Read',
                    'input' => ['file_path' => '/app/config.php'],
                    'id' => 'toolu_def456',
                ],
            ],
        ],
    ]);

    $logs = TaskLog::where('yak_task_id', $this->task->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2);
    expect($logs[0]->metadata['type'])->toBe('tool_use');
    expect($logs[0]->metadata['tool'])->toBe('Read');
    expect($logs[1]->metadata['type'])->toBe('assistant');
    expect($logs[1]->message)->toBe('Let me check the files.');
});

test('tool_result works after tool_use extracted from assistant message', function () {
    $this->handler->handle([
        'type' => 'assistant',
        'message' => [
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'Bash',
                    'input' => ['command' => 'echo hello', 'description' => 'Say hello'],
                    'id' => 'toolu_ghi789',
                ],
            ],
        ],
    ]);

    $this->handler->handle([
        'type' => 'tool_result',
        'content' => 'hello',
    ]);

    $log = TaskLog::where('yak_task_id', $this->task->id)->first();
    expect($log->message)->toContain('→ exit 0');
    expect($log->metadata['output'])->toBe('hello');
});
