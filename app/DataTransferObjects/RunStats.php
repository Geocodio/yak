<?php

namespace App\DataTransferObjects;

/**
 * What the stream loop observed during one agent run, accumulated by
 * StreamEventHandler as events go by: tool calls and their outcomes,
 * API retries, assistant turns, and per-model token usage summed from
 * the assistant messages themselves. The last one is what lets a run
 * whose `result` event never arrived still get a cost.
 *
 * Deliberately mutable: it is filled in over the life of a stream.
 */
final class RunStats
{
    public int $toolCalls = 0;

    public int $toolErrors = 0;

    public int $toolMs = 0;

    public int $mcpCalls = 0;

    /** @var array<string, array{calls: int, errors: int, ms: int}> */
    public array $tools = [];

    public int $apiRetries = 0;

    /** @var array<string, int> */
    public array $apiRetryBreakdown = [];

    public int $assistantMessages = 0;

    public int $malformedLines = 0;

    public bool $resumedInPlace = false;

    public ?string $forcedTermination = null;

    public ?string $cliVersion = null;

    /** @var array<string, array{input: int, output: int, cache_read: int, cache_creation: int}> */
    public array $usageByModel = [];

    /** @var array<string, true> */
    private array $seenMessageIds = [];

    public function toolStarted(string $tool): void
    {
        $this->toolCalls++;

        if (str_starts_with($tool, 'mcp__')) {
            $this->mcpCalls++;
        }

        $this->tools[$tool] ??= ['calls' => 0, 'errors' => 0, 'ms' => 0];
        $this->tools[$tool]['calls']++;
    }

    public function toolFinished(string $tool, bool $isError, ?int $durationMs): void
    {
        $this->tools[$tool] ??= ['calls' => 0, 'errors' => 0, 'ms' => 0];

        if ($isError) {
            $this->toolErrors++;
            $this->tools[$tool]['errors']++;
        }

        if ($durationMs !== null) {
            $this->toolMs += $durationMs;
            $this->tools[$tool]['ms'] += $durationMs;
        }
    }

    public function apiRetry(string $error): void
    {
        $this->apiRetries++;
        $this->apiRetryBreakdown[$error] = ($this->apiRetryBreakdown[$error] ?? 0) + 1;
    }

    /**
     * Sum an assistant message's usage. The CLI emits one `assistant`
     * event per content block of the same message, each carrying the
     * same `message.id` and usage, so the id dedupes the repeats.
     *
     * @param  array<string, mixed>  $usage
     */
    public function assistantMessage(?string $messageId, ?string $model, array $usage): void
    {
        if ($messageId !== null) {
            if (isset($this->seenMessageIds[$messageId])) {
                return;
            }
            $this->seenMessageIds[$messageId] = true;
        }

        $this->assistantMessages++;

        if ($model === null || $model === '' || $usage === []) {
            return;
        }

        $this->usageByModel[$model] ??= ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];
        $this->usageByModel[$model]['input'] += (int) ($usage['input_tokens'] ?? 0);
        $this->usageByModel[$model]['output'] += (int) ($usage['output_tokens'] ?? 0);
        $this->usageByModel[$model]['cache_read'] += (int) ($usage['cache_read_input_tokens'] ?? 0);
        $this->usageByModel[$model]['cache_creation'] += (int) ($usage['cache_creation_input_tokens'] ?? 0);
    }

    /**
     * Token totals across every model seen in the stream, in the same
     * shape the `result` event reports.
     *
     * @return array{input_tokens: int, output_tokens: int, cache_read_input_tokens: int, cache_creation_input_tokens: int}
     */
    public function usageTotals(): array
    {
        $totals = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0];

        foreach ($this->usageByModel as $usage) {
            $totals['input_tokens'] += $usage['input'];
            $totals['output_tokens'] += $usage['output'];
            $totals['cache_read_input_tokens'] += $usage['cache_read'];
            $totals['cache_creation_input_tokens'] += $usage['cache_creation'];
        }

        return $totals;
    }
}
