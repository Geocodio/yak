<?php

namespace App\DataTransferObjects;

/**
 * A `yak.tool_call` line from the runner: Claude asked Yak to run one of its tools.
 */
final readonly class YakToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $callId,
        public string $tool,
        public array $input,
    ) {}

    /**
     * @param  array<string, mixed>  $line
     */
    public static function fromLine(array $line): ?self
    {
        if (($line['type'] ?? null) !== 'yak.tool_call' || ! is_string($line['call_id'] ?? null) || ! is_string($line['tool'] ?? null)) {
            return null;
        }

        return new self($line['call_id'], $line['tool'], is_array($line['input'] ?? null) ? $line['input'] : []);
    }
}
