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
        public ?string $errorSubtype = null,
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

    /**
     * Human-readable failure reason for Task `error_log` and source-channel
     * notifications. Prefers Claude's own result text when non-empty; falls
     * back to a message shaped by `errorSubtype`, number of turns, cost,
     * and per-task budget — so instead of "Agent returned an error or
     * malformed output" we say "Hit per-task budget cap ($5.03 / $5.00
     * after 84 turns)" or "Hit max turns limit (300 turns, $3.21)".
     */
    public function failureMessage(): string
    {
        if ($this->resultSummary !== '') {
            return $this->resultSummary;
        }

        $maxBudget = (float) config('yak.max_budget_per_task', 5);
        $cost = sprintf('%.2f', $this->costUsd);
        $budget = sprintf('%.2f', $maxBudget);

        // Claude's SDK reports subtype=error_during_execution both for hard
        // SDK failures AND for budget exhaustion (the CLI wraps the cap
        // check in a throw). Distinguish by checking cost vs cap.
        if ($this->errorSubtype === 'error_during_execution' && $this->costUsd >= $maxBudget * 0.99) {
            return "Hit per-task budget cap (\${$cost} / \${$budget} after {$this->numTurns} turns)";
        }

        return match ($this->errorSubtype) {
            'error_max_turns' => "Hit max turns limit ({$this->numTurns} turns, \${$cost})",
            'error_during_execution' => "Agent error during execution after {$this->numTurns} turns (cost \${$cost})",
            default => "Agent returned an error after {$this->numTurns} turns (cost \${$cost})",
        };
    }
}
