<?php

use App\Agents\ClaudeCodeRunner;
use App\DataTransferObjects\AgentRunRequest;

uses()->group('contract');

function claudeContractRequest(string $prompt, ?string $resume = null): AgentRunRequest
{
    return new AgentRunRequest(
        prompt: $prompt,
        systemPrompt: '',
        workingDirectory: sys_get_temp_dir(),
        timeoutSeconds: 120,
        maxBudgetUsd: 0.05,
        maxTurns: 1,
        model: (string) config('yak.default_model'),
        resumeSessionId: $resume,
    );
}

it('returns a populated AgentRunResult from the real Claude Code CLI', function () {
    $result = (new ClaudeCodeRunner)->run(claudeContractRequest('Respond with: hello'));

    expect($result->isError)->toBeFalse()
        ->and($result->sessionId)->toBeString()->not->toBeEmpty()
        ->and($result->resultSummary)->toBeString()
        ->and($result->costUsd)->toBeFloat()
        ->and($result->numTurns)->toBeInt()->toBeGreaterThanOrEqual(1)
        ->and($result->durationMs)->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('resumes a Claude Code session via resumeSessionId', function () {
    $first = (new ClaudeCodeRunner)->run(claudeContractRequest('Say exactly: first'));

    expect($first->isError)->toBeFalse()->and($first->sessionId)->not->toBeEmpty();

    $resumed = (new ClaudeCodeRunner)->run(claudeContractRequest('Say exactly: resumed', $first->sessionId));

    expect($resumed->isError)->toBeFalse()
        ->and($resumed->sessionId)->toBeString()->not->toBeEmpty();
});

it('keeps costs within the requested budget', function () {
    $result = (new ClaudeCodeRunner)->run(new AgentRunRequest(
        prompt: 'Respond with: budget test',
        systemPrompt: '',
        workingDirectory: sys_get_temp_dir(),
        timeoutSeconds: 120,
        maxBudgetUsd: 0.10,
        maxTurns: 2,
        model: (string) config('yak.default_model'),
    ));

    expect($result->isError)->toBeFalse()
        ->and($result->costUsd)->toBeLessThanOrEqual(0.15); // small overhead margin
});
