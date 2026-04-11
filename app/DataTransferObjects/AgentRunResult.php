<?php

namespace App\DataTransferObjects;

final readonly class AgentRunResult
{
    /**
     * @param  array<int, string>  $clarificationOptions
     */
    public function __construct(
        public string $sessionId,
        public string $resultSummary,
        public float $costUsd,
        public int $numTurns,
        public int $durationMs,
        public bool $isError,
        public bool $clarificationNeeded,
        public array $clarificationOptions,
        public string $rawOutput,
    ) {}

    public static function failure(string $reason, string $rawOutput): self
    {
        return new self(
            sessionId: '',
            resultSummary: $reason,
            costUsd: 0.0,
            numTurns: 0,
            durationMs: 0,
            isError: true,
            clarificationNeeded: false,
            clarificationOptions: [],
            rawOutput: $rawOutput,
        );
    }
}
