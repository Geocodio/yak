<?php

namespace App\DataTransferObjects;

final readonly class AgentRunRequest
{
    public function __construct(
        public string $prompt,
        public string $systemPrompt,
        public string $workingDirectory,
        public int $timeoutSeconds,
        public float $maxBudgetUsd,
        public int $maxTurns,
        public string $model,
        public ?string $resumeSessionId = null,
        public ?string $mcpConfigPath = null,
    ) {}

    public function isResume(): bool
    {
        return $this->resumeSessionId !== null && $this->resumeSessionId !== '';
    }
}
