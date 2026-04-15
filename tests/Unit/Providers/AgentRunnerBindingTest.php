<?php

use App\Agents\SandboxedAgentRunner;
use App\Contracts\AgentRunner;
use Tests\TestCase;

uses(TestCase::class);

it('resolves AgentRunner to SandboxedAgentRunner', function () {
    $runner = app(AgentRunner::class);

    expect($runner)->toBeInstanceOf(SandboxedAgentRunner::class);
});
