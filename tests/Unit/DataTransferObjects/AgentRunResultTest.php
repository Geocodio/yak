<?php

use App\DataTransferObjects\AgentRunResult;

it('constructs a success result with all metric fields', function () {
    $result = new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Fixed the bug',
        costUsd: 2.5,
        numTurns: 15,
        durationMs: 120000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{"result":"Fixed the bug"}',
    );

    expect($result->sessionId)->toBe('sess_1')
        ->and($result->resultSummary)->toBe('Fixed the bug')
        ->and($result->costUsd)->toBe(2.5)
        ->and($result->numTurns)->toBe(15)
        ->and($result->durationMs)->toBe(120000)
        ->and($result->isError)->toBeFalse()
        ->and($result->clarificationNeeded)->toBeFalse()
        ->and($result->clarificationOptions)->toBe([]);
});

it('constructs a clarification result with options', function () {
    $result = new AgentRunResult(
        sessionId: 'sess_2',
        resultSummary: 'Need more info',
        costUsd: 0.3,
        numTurns: 2,
        durationMs: 15000,
        isError: false,
        clarificationNeeded: true,
        clarificationOptions: ['Option A', 'Option B'],
        rawOutput: '{}',
    );

    expect($result->clarificationNeeded)->toBeTrue()
        ->and($result->clarificationOptions)->toBe(['Option A', 'Option B']);
});

it('failure() factory builds an error result', function () {
    $result = AgentRunResult::failure('malformed output', 'not json');

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toBe('malformed output')
        ->and($result->rawOutput)->toBe('not json')
        ->and($result->sessionId)->toBe('')
        ->and($result->costUsd)->toBe(0.0)
        ->and($result->numTurns)->toBe(0)
        ->and($result->durationMs)->toBe(0)
        ->and($result->clarificationNeeded)->toBeFalse()
        ->and($result->clarificationOptions)->toBe([]);
});
