<?php

namespace App\DataTransferObjects;

use App\Models\YakTask;

final readonly class AgentRunRequest
{
    public function __construct(
        public string $prompt,
        public string $systemPrompt,
        public string $containerName,
        public int $timeoutSeconds,
        public float $maxBudgetUsd,
        public int $maxTurns,
        public string $model,
        public ?string $resumeSessionId = null,
        public ?string $mcpConfigPath = null,
        public ?YakTask $task = null,
    ) {}

    public function isResume(): bool
    {
        return $this->resumeSessionId !== null && $this->resumeSessionId !== '';
    }

    /**
     * Copy of this request with the resume session stripped, for retrying
     * a run whose `--resume` failed because the transcript is gone.
     */
    public function withoutResume(): self
    {
        return new self(
            prompt: $this->prompt,
            systemPrompt: $this->systemPrompt,
            containerName: $this->containerName,
            timeoutSeconds: $this->timeoutSeconds,
            maxBudgetUsd: $this->maxBudgetUsd,
            maxTurns: $this->maxTurns,
            model: $this->model,
            resumeSessionId: null,
            mcpConfigPath: $this->mcpConfigPath,
            task: $this->task,
        );
    }
}
