<?php

use App\DataTransferObjects\AgentRunRequest;

it('holds all fields for a fresh run', function () {
    $request = new AgentRunRequest(
        prompt: 'Fix the bug',
        systemPrompt: 'You are Yak',
        containerName: '/home/yak/repos/test',
        timeoutSeconds: 570,
        maxBudgetUsd: 5.0,
        maxTurns: 40,
        model: 'opus',
        resumeSessionId: null,
        mcpConfigPath: null,
    );

    expect($request->prompt)->toBe('Fix the bug')
        ->and($request->systemPrompt)->toBe('You are Yak')
        ->and($request->containerName)->toBe('/home/yak/repos/test')
        ->and($request->timeoutSeconds)->toBe(570)
        ->and($request->maxBudgetUsd)->toBe(5.0)
        ->and($request->maxTurns)->toBe(40)
        ->and($request->model)->toBe('opus')
        ->and($request->resumeSessionId)->toBeNull()
        ->and($request->mcpConfigPath)->toBeNull()
        ->and($request->isResume())->toBeFalse();
});

it('reports isResume() true when a session id is provided', function () {
    $request = new AgentRunRequest(
        prompt: 'Retry',
        systemPrompt: 'sys',
        containerName: '/x',
        timeoutSeconds: 60,
        maxBudgetUsd: 1.0,
        maxTurns: 10,
        model: 'opus',
        resumeSessionId: 'sess_abc',
    );

    expect($request->isResume())->toBeTrue()
        ->and($request->resumeSessionId)->toBe('sess_abc');
});
