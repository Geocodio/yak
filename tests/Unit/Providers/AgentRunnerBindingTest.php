<?php

use App\Agents\BrokerAgentRunner;
use App\Contracts\AgentRunner;
use Tests\TestCase;

uses(TestCase::class);

it('resolves AgentRunner to BrokerAgentRunner', function () {
    $runner = app(AgentRunner::class);

    expect($runner)->toBeInstanceOf(BrokerAgentRunner::class);
});
