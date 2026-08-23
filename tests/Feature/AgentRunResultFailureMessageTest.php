<?php

use App\DataTransferObjects\AgentRunResult;

it('failureMessage() appends CLI stderr when Claude returned no result text', function () {
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
        stderr: "No conversation found with session ID: 98894ba5\n",
    );

    expect($result->failureMessage())
        ->toBe('Agent error during execution after 0 turns (cost $0.00) — No conversation found with session ID: 98894ba5');
});
