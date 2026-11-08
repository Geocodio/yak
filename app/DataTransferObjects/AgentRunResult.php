<?php

namespace App\DataTransferObjects;

final readonly class AgentRunResult
{
    /**
     * @param  list<ClarificationQuestion>  $clarificationQuestions
     */
    public function __construct(
        public string $sessionId,
        public string $resultSummary,
        public float $costUsd,
        public int $numTurns,
        public int $durationMs,
        public bool $isError,
        public string $rawOutput,
        public ?string $errorSubtype = null,
        public string $stderr = '',
        public ?RunUsage $usage = null,
        public ?RunStats $stats = null,
        public int $permissionDenials = 0,
        public bool $synthesized = false,
        public bool $wrongRepository = false,
        public ?string $wrongRepositoryReason = null,
        public ?string $suggestedRepository = null,
        public array $clarificationQuestions = [],
        public ?float $sessionCostTotalUsd = null,
    ) {}

    public function needsClarification(): bool
    {
        return $this->clarificationQuestions !== [];
    }

    /**
     * Keeps the CLI's cumulative session total and sets costUsd to this
     * run's own share of it.
     */
    public function withIncrementalCost(float $costUsd): self
    {
        return $this->copy(['sessionCostTotalUsd' => $this->costUsd, 'costUsd' => $costUsd]);
    }

    public function withStderr(string $stderr): self
    {
        return $this->copy(['stderr' => $stderr]);
    }

    public function withStats(RunStats $stats): self
    {
        return $this->copy(['stats' => $stats]);
    }

    public function withResultSummary(string $resultSummary): self
    {
        return $this->copy(['resultSummary' => $resultSummary]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function copy(array $overrides): self
    {
        /** @var array<string, mixed> $values */
        $values = array_merge(get_object_vars($this), $overrides);

        return new self(...$values);
    }

    public static function failure(string $reason, string $rawOutput): self
    {
        return new self(
            sessionId: '',
            resultSummary: $reason,
            costUsd: 0.0,
            numTurns: 0,
            durationMs: 0,
            isError: true,
            rawOutput: $rawOutput,
        );
    }

    /**
     * Human-readable failure reason for Task `error_log` and source-channel
     * notifications. Prefers Claude's own result text when non-empty; falls
     * back to a message shaped by `errorSubtype`, number of turns, cost,
     * and cost — so instead of "Agent returned an error or malformed
     * output" we say "Hit max turns limit (300 turns, $3.21)".
     */
    public function failureMessage(): string
    {
        if ($this->resultSummary !== '') {
            return $this->resultSummary;
        }

        $stderrSuffix = trim($this->stderr) !== ''
            ? ' — ' . substr(trim($this->stderr), 0, 500)
            : '';

        $cost = sprintf('%.2f', $this->costUsd);

        return match ($this->errorSubtype) {
            'error_max_turns' => "Hit max turns limit ({$this->numTurns} turns, \${$cost})",
            'error_during_execution' => "Agent error during execution after {$this->numTurns} turns (cost \${$cost}){$stderrSuffix}",
            default => "Agent returned an error after {$this->numTurns} turns (cost \${$cost}){$stderrSuffix}",
        };
    }

    /**
     * Failure category for the run row and the failure-taxonomy chart:
     * the CLI subtype when it gave one, otherwise a stable label derived
     * from the same heuristics failureMessage() uses.
     */
    public function failureCategory(): ?string
    {
        if (! $this->isError) {
            return null;
        }

        return $this->errorSubtype ?? 'agent_error';
    }
}
