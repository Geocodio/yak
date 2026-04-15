<?php

use App\Agents\ClaudeCodeRunner;
use App\Agents\SandboxedAgentRunner;
use App\Contracts\AgentRunner;
use Tests\TestCase;

uses(TestCase::class);

it('resolves AgentRunner to SandboxedAgentRunner by default', function () {
    config(['yak.agent_runner' => 'sandbox']);

    $runner = app(AgentRunner::class);

    expect($runner)->toBeInstanceOf(SandboxedAgentRunner::class);
});

it('resolves AgentRunner to ClaudeCodeRunner when configured', function () {
    config(['yak.agent_runner' => 'claude_code']);

    $runner = app(AgentRunner::class);

    expect($runner)->toBeInstanceOf(ClaudeCodeRunner::class);
});

it('throws a clear error when an unknown runner is configured', function () {
    config(['yak.agent_runner' => 'nonexistent']);

    app(AgentRunner::class);
})->throws(InvalidArgumentException::class, 'Unknown Yak agent runner: nonexistent');
