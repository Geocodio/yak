<?php

namespace App\DataTransferObjects;

/**
 * Token usage for one agent run, as reported by the CLI's `result` event
 * (or, when that event never arrived, summed from the assistant messages
 * in the stream). Input tokens exclude cache reads and writes, matching
 * the Anthropic API's own accounting.
 */
final readonly class RunUsage
{
    /**
     * @param  array<string, array{input: int, output: int, cache_read: int, cache_creation: int, cost_usd: float|null}>  $modelUsage
     */
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheCreationTokens = 0,
        public ?int $apiDurationMs = null,
        public array $modelUsage = [],
    ) {}

    /**
     * @param  array<string, mixed>  $decoded  A decoded `result` event.
     */
    public static function fromResultEvent(array $decoded): self
    {
        /** @var array<string, mixed> $usage */
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        $modelUsage = [];
        if (is_array($decoded['modelUsage'] ?? null)) {
            foreach ($decoded['modelUsage'] as $model => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $modelUsage[(string) $model] = [
                    'input' => (int) ($row['inputTokens'] ?? 0),
                    'output' => (int) ($row['outputTokens'] ?? 0),
                    'cache_read' => (int) ($row['cacheReadInputTokens'] ?? 0),
                    'cache_creation' => (int) ($row['cacheCreationInputTokens'] ?? 0),
                    'cost_usd' => isset($row['costUSD']) ? (float) $row['costUSD'] : null,
                ];
            }
        }

        return new self(
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheCreationTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            apiDurationMs: isset($decoded['duration_api_ms']) ? (int) $decoded['duration_api_ms'] : null,
            modelUsage: $modelUsage,
        );
    }

    public function isEmpty(): bool
    {
        return $this->inputTokens === 0
            && $this->outputTokens === 0
            && $this->cacheReadTokens === 0
            && $this->cacheCreationTokens === 0;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheCreationTokens;
    }
}
