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
        public string $stderr = '',
        public ?RunUsage $usage = null,
        public ?RunStats $stats = null,
        public int $permissionDenials = 0,
        public bool $synthesized = false,
        public bool $staleSessionRetry = false,
    ) {}

    public function withStderr(string $stderr): self
    {
        return $this->copy(['stderr' => $stderr]);
    }

    public function withStats(RunStats $stats): self
    {
        return $this->copy(['stats' => $stats]);
    }

    /**
     * Marks a result produced by the non-resumed re-run that
     * RetriesWithoutStaleSession falls back to.
     */
    public function withStaleSessionRetry(): self
    {
        return $this->copy(['staleSessionRetry' => true]);
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

    /**
     * True when the CLI refused to `--resume` because the transcript for
     * the requested session ID is not present in the sandbox — the session
     * is stale, not the task itself. Callers can retry without resume.
     */
    public function isStaleSessionResume(): bool
    {
        return $this->isError
            && (str_contains($this->stderr, 'No conversation found with session ID')
                || str_contains($this->resultSummary, 'No conversation found with session ID'));
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

        $stderrSuffix = trim($this->stderr) !== ''
            ? ' — ' . substr(trim($this->stderr), 0, 500)
            : '';

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

        $maxBudget = (float) config('yak.max_budget_per_task', 5);

        if ($this->errorSubtype === 'error_during_execution' && $this->costUsd >= $maxBudget * 0.99) {
            return 'budget_cap';
        }

        if ($this->isStaleSessionResume()) {
            return 'stale_session';
        }

        return $this->errorSubtype ?? 'agent_error';
    }
}
