<?php

namespace Tests\Support;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use RuntimeException;

class FakeAgentRunner implements AgentRunner
{
    /** @var array<int, AgentRunRequest> */
    public array $calls = [];

    /** @var array<int, AgentRunResult|\Throwable> */
    private array $queue = [];

    public function queueResult(AgentRunResult $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function queueException(\Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function run(AgentRunRequest $request): AgentRunResult
    {
        $this->calls[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakeAgentRunner has no queued result. Call queueResult() before run().');
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastCall(): ?AgentRunRequest
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)];
    }
}
