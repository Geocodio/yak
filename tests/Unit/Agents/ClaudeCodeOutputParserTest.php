<?php

use App\Agents\ClaudeCodeOutputParser;

it('parses a successful Claude Code JSON payload', function () {
    $json = json_encode([
        'result' => 'Fixed the bug successfully',
        'cost_usd' => 2.50,
        'session_id' => 'sess_success123',
        'num_turns' => 15,
        'duration_ms' => 120000,
        'is_error' => false,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->sessionId)->toBe('sess_success123')
        ->and($result->resultSummary)->toBe('Fixed the bug successfully')
        ->and($result->costUsd)->toBe(2.5)
        ->and($result->numTurns)->toBe(15)
        ->and($result->durationMs)->toBe(120000)
        ->and($result->isError)->toBeFalse()
        ->and($result->clarificationNeeded)->toBeFalse()
        ->and($result->clarificationOptions)->toBe([])
        ->and($result->rawOutput)->toBe($json);
});

it('parses a clarification payload', function () {
    $json = json_encode([
        'result' => 'Need clarification',
        'session_id' => 'sess_2',
        'cost_usd' => 0.3,
        'num_turns' => 2,
        'duration_ms' => 15000,
        'is_error' => false,
        'clarification_needed' => true,
        'options' => ['Upgrade dependency', 'Pin version'],
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->clarificationNeeded)->toBeTrue()
        ->and($result->clarificationOptions)->toBe(['Upgrade dependency', 'Pin version'])
        ->and($result->isError)->toBeFalse();
});

it('parses an error payload with is_error true', function () {
    $json = json_encode([
        'result' => 'Budget exceeded',
        'session_id' => 'sess_3',
        'cost_usd' => 5.0,
        'num_turns' => 40,
        'duration_ms' => 600000,
        'is_error' => true,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toBe('Budget exceeded')
        ->and($result->costUsd)->toBe(5.0);
});

it('returns a failure result when output is not JSON', function () {
    $result = ClaudeCodeOutputParser::parse('this is not json');

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toBe('Claude Code returned malformed output')
        ->and($result->rawOutput)->toBe('this is not json')
        ->and($result->sessionId)->toBe('');
});

it('accepts result_summary as an alias for result', function () {
    $json = json_encode([
        'result_summary' => 'Alternate key',
        'session_id' => 'sess_4',
        'is_error' => false,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->resultSummary)->toBe('Alternate key');
});

it('defaults missing numeric fields to zero', function () {
    $json = json_encode([
        'result' => 'ok',
        'session_id' => 'sess_5',
        'is_error' => false,
    ]);

    $result = ClaudeCodeOutputParser::parse($json);

    expect($result->costUsd)->toBe(0.0)
        ->and($result->numTurns)->toBe(0)
        ->and($result->durationMs)->toBe(0);
});
