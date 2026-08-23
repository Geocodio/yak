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

it('withoutResume() strips the resume session id but keeps everything else', function () {
    $request = new AgentRunRequest(
        prompt: 'do the thing',
        systemPrompt: 'system',
        containerName: 'task-1',
        timeoutSeconds: 600,
        maxBudgetUsd: 5.0,
        maxTurns: 300,
        model: 'opus',
        resumeSessionId: 'sess-abc',
        mcpConfigPath: '/home/yak/mcp-config.json',
    );

    $fresh = $request->withoutResume();

    expect($fresh->isResume())->toBeFalse()
        ->and($fresh->resumeSessionId)->toBeNull()
        ->and($fresh->prompt)->toBe('do the thing')
        ->and($fresh->systemPrompt)->toBe('system')
        ->and($fresh->containerName)->toBe('task-1')
        ->and($fresh->timeoutSeconds)->toBe(600)
        ->and($fresh->maxBudgetUsd)->toBe(5.0)
        ->and($fresh->maxTurns)->toBe(300)
        ->and($fresh->model)->toBe('opus')
        ->and($fresh->mcpConfigPath)->toBe('/home/yak/mcp-config.json')
        ->and($request->resumeSessionId)->toBe('sess-abc');
});
