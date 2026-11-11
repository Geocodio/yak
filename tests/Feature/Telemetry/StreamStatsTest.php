<?php

use App\Agents\ClaudeCodeOutputParser;
use App\Agents\StreamEventHandler;
use App\DataTransferObjects\RunStats;
use App\Models\YakTask;

beforeEach(function () {
    $this->task = YakTask::factory()->running()->create();
    $this->stats = new RunStats;
    $this->handler = new StreamEventHandler($this->task, $this->stats);
});

test('tool calls, errors and durations are accumulated per tool', function () {
    $this->handler->handle(['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [
        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'Bash', 'input' => ['command' => 'ls']],
        ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'mcp__linear__get_issue', 'input' => ['id' => 'ENG-1']],
    ]]]);
    $this->handler->handle(['type' => 'user', 'message' => ['content' => [
        ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'Exit code 1', 'is_error' => true],
        ['type' => 'tool_result', 'tool_use_id' => 'tu_2', 'content' => '{}'],
    ]]]);

    expect($this->stats->toolCalls)->toBe(2)
        ->and($this->stats->toolErrors)->toBe(1)
        ->and($this->stats->mcpCalls)->toBe(1)
        ->and($this->stats->tools['Bash']['errors'])->toBe(1)
        ->and($this->stats->tools['mcp__linear__get_issue']['calls'])->toBe(1)
        ->and($this->stats->toolMs)->toBeGreaterThanOrEqual(0);
});

test('assistant usage is summed per model and deduplicated by message id', function () {
    $usage = ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 10];

    // The CLI emits one assistant event per content block of the same message.
    $this->handler->handle(['type' => 'assistant', 'message' => ['id' => 'm1', 'model' => 'claude-opus-4-6', 'usage' => $usage, 'content' => [['type' => 'text', 'text' => 'thinking']]]]);
    $this->handler->handle(['type' => 'assistant', 'message' => ['id' => 'm1', 'model' => 'claude-opus-4-6', 'usage' => $usage, 'content' => [['type' => 'tool_use', 'id' => 'tu', 'name' => 'Read', 'input' => []]]]]);
    $this->handler->handle(['type' => 'assistant', 'message' => ['id' => 'm2', 'model' => 'claude-haiku-4-5-20251001', 'usage' => ['input_tokens' => 5, 'output_tokens' => 5], 'content' => [['type' => 'text', 'text' => 'sub']]]]);

    expect($this->stats->assistantMessages)->toBe(2)
        ->and($this->stats->usageByModel['claude-opus-4-6'])->toBe(['input' => 100, 'output' => 50, 'cache_read' => 1000, 'cache_creation' => 10])
        ->and($this->stats->usageByModel['claude-haiku-4-5-20251001']['input'])->toBe(5)
        ->and($this->stats->usageTotals())->toBe(['input_tokens' => 105, 'output_tokens' => 55, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 10]);
});

test('api retry system events are counted by error class', function () {
    $this->handler->handle(['type' => 'system', 'subtype' => 'api_retry', 'attempt' => 1, 'error' => 'rate_limit']);
    $this->handler->handle(['type' => 'system', 'subtype' => 'api_retry', 'attempt' => 2, 'error' => 'overloaded']);
    $this->handler->handle(['type' => 'system', 'subtype' => 'api_retry', 'attempt' => 3, 'error' => 'rate_limit']);
    $this->handler->handle(['type' => 'system', 'subtype' => 'init', 'session_id' => 's']);

    expect($this->stats->apiRetries)->toBe(3)
        ->and($this->stats->apiRetryBreakdown)->toBe(['rate_limit' => 2, 'overloaded' => 1]);
});

test('the parser keeps token usage, api duration, model usage and permission denials from a result event', function () {
    $result = ClaudeCodeOutputParser::parse(json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'result' => 'done',
        'session_id' => 'sess',
        'num_turns' => 3,
        'total_cost_usd' => 0.42,
        'duration_ms' => 5000,
        'duration_api_ms' => 3200,
        'usage' => ['input_tokens' => 12, 'output_tokens' => 34, 'cache_read_input_tokens' => 560, 'cache_creation_input_tokens' => 78],
        'modelUsage' => ['claude-opus-4-6' => ['inputTokens' => 12, 'outputTokens' => 34, 'cacheReadInputTokens' => 560, 'cacheCreationInputTokens' => 78, 'costUSD' => 0.42]],
        'permission_denials' => [['tool_name' => 'Bash'], ['tool_name' => 'Write']],
    ]));

    expect($result->usage?->inputTokens)->toBe(12)
        ->and($result->usage?->outputTokens)->toBe(34)
        ->and($result->usage?->cacheReadTokens)->toBe(560)
        ->and($result->usage?->cacheCreationTokens)->toBe(78)
        ->and($result->usage?->apiDurationMs)->toBe(3200)
        ->and($result->usage?->modelUsage['claude-opus-4-6']['cost_usd'])->toBe(0.42)
        ->and($result->permissionDenials)->toBe(2)
        ->and($result->synthesized)->toBeFalse();
});
