<?php

namespace App\Contracts;

use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;

interface AgentRunner
{
    /**
     * Execute an agent run. Implementations are responsible for command
     * building, process invocation, output parsing, and auth error
     * detection. Implementations MAY throw auth-related exceptions that
     * callers are expected to catch at the job layer.
     */
    public function run(AgentRunRequest $request): AgentRunResult;
}
