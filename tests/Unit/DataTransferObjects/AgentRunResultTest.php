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

it('withStderr() returns a copy carrying the stderr while preserving all other fields', function () {
    $result = AgentRunResult::failure('boom', 'raw');

    $withStderr = $result->withStderr('No conversation found with session ID: abc');

    expect($withStderr)->not->toBe($result)
        ->and($withStderr->stderr)->toBe('No conversation found with session ID: abc')
        ->and($withStderr->resultSummary)->toBe('boom')
        ->and($withStderr->rawOutput)->toBe('raw')
        ->and($withStderr->isError)->toBeTrue()
        ->and($result->stderr)->toBe('');
});

it('failureMessage() prefers Claude result text and does not append stderr to it', function () {
    $result = AgentRunResult::failure('Claude explained the failure', '')
        ->withStderr('some warning noise');

    expect($result->failureMessage())->toBe('Claude explained the failure');
});

it('isStaleSessionResume() detects the missing-session CLI error in stderr', function () {
    $result = new AgentRunResult(
        sessionId: '',
        resultSummary: '',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
        errorSubtype: 'error_during_execution',
        stderr: 'No conversation found with session ID: 98894ba5-8938-4237-b532-ca72c4fa97c7',
    );

    expect($result->isStaleSessionResume())->toBeTrue();
});

it('isStaleSessionResume() detects the missing-session error in the result summary', function () {
    $result = AgentRunResult::failure('No conversation found with session ID: abc', '');

    expect($result->isStaleSessionResume())->toBeTrue();
});

it('isStaleSessionResume() is false for unrelated errors and for successes', function () {
    expect(AgentRunResult::failure('some other error', '')->isStaleSessionResume())->toBeFalse();

    $success = new AgentRunResult(
        sessionId: 'sess',
        resultSummary: 'done',
        costUsd: 1.0,
        numTurns: 5,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    );

    expect($success->isStaleSessionResume())->toBeFalse();
});
