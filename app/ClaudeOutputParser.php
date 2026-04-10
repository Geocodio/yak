<?php

namespace App;

class ClaudeOutputParser
{
    /** @var array<string, mixed> */
    private array $parsed;

    private bool $valid;

    public function __construct(string $output)
    {
        $decoded = json_decode($output, true);

        if (is_array($decoded)) {
            $this->parsed = $decoded;
            $this->valid = true;
        } else {
            $this->parsed = [];
            $this->valid = false;
        }
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function isError(): bool
    {
        if (! $this->valid) {
            return true;
        }

        return ($this->parsed['is_error'] ?? false) === true;
    }

    public function isClarification(): bool
    {
        if (! $this->valid) {
            return false;
        }

        return ($this->parsed['clarification_needed'] ?? false) === true;
    }

    /**
     * @return array<int, string>
     */
    public function clarificationOptions(): array
    {
        if (! $this->isClarification()) {
            return [];
        }

        $options = $this->parsed['options'] ?? [];

        return is_array($options) ? array_values($options) : [];
    }

    public function resultSummary(): string
    {
        return (string) ($this->parsed['result'] ?? $this->parsed['result_summary'] ?? '');
    }

    public function costUsd(): float
    {
        return (float) ($this->parsed['cost_usd'] ?? 0);
    }

    public function sessionId(): string
    {
        return (string) ($this->parsed['session_id'] ?? '');
    }

    public function numTurns(): int
    {
        return (int) ($this->parsed['num_turns'] ?? 0);
    }

    public function durationMs(): int
    {
        return (int) ($this->parsed['duration_ms'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->parsed;
    }
}
