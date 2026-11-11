<?php

namespace App\Services\Threads;

/**
 * Reduces a runner line to the compact events the thread page shows. Streaming
 * deltas are not projected here; the DeltaBatcher handles them.
 */
class ThreadEventProjector
{
    public const MAX_TOOL_RESULT_CHARACTERS = 4000;

    /**
     * @param  array<string, mixed>  $line
     * @param  bool  $hasOpenBlock  whether a streaming block is open when the line arrives
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function project(array $line, bool $hasOpenBlock = false): array
    {
        return match ($line['type'] ?? null) {
            'assistant' => $this->assistant($line),
            'user' => $this->user($line, $hasOpenBlock),
            'system' => ($line['subtype'] ?? null) === 'init' ? [$this->entry(array_filter([
                'role' => 'system',
                'subtype' => 'init',
                'model' => $line['model'] ?? null,
                'sessionId' => $line['session_id'] ?? null,
            ], fn (mixed $value): bool => $value !== null))] : [],
            'result' => [$this->entry([
                'role' => 'result',
                'subtype' => $line['subtype'] ?? null,
                'isError' => ($line['is_error'] ?? false) === true,
                'text' => is_string($line['result'] ?? null) ? $line['result'] : null,
                'costUsd' => $line['total_cost_usd'] ?? null,
                'durationMs' => $line['duration_ms'] ?? null,
                'numTurns' => $line['num_turns'] ?? null,
            ])],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function assistant(array $line): array
    {
        $message = (array) ($line['message'] ?? []);
        $blocks = [];

        foreach ((array) ($message['content'] ?? []) as $block) {
            $blocks[] = match ($block['type'] ?? null) {
                'text' => ['kind' => 'text', 'text' => (string) ($block['text'] ?? '')],
                'thinking' => ['kind' => 'thinking', 'text' => (string) ($block['thinking'] ?? '')],
                'tool_use' => ['kind' => 'tool_use', 'toolUseId' => $block['id'] ?? null, 'name' => $block['name'] ?? null, 'input' => $block['input'] ?? (object) []],
                default => null,
            };
        }

        $blocks = array_values(array_filter($blocks));

        return $blocks === [] ? [] : [$this->entry(['role' => 'assistant', 'messageId' => $message['id'] ?? null, 'blocks' => $blocks])];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function user(array $line, bool $hasOpenBlock): array
    {
        if (($line['isSynthetic'] ?? false) === true) {
            return $hasOpenBlock ? [['type' => 'stream.reset', 'data' => []]] : [];
        }

        $content = $line['message']['content'] ?? null;
        $entries = [];

        if (is_string($line['uuid'] ?? null) && ($text = $this->promptText($content)) !== null) {
            return [$this->entry(['role' => 'user', 'uuid' => $line['uuid'], 'text' => $text, 'kind' => 'prompt'])];
        }

        foreach (is_array($content) ? $content : [] as $block) {
            if (($block['type'] ?? null) !== 'tool_result') {
                continue;
            }

            $text = $this->resultText($block['content'] ?? '');
            $isTruncated = mb_strlen($text) > self::MAX_TOOL_RESULT_CHARACTERS;

            $entries[] = $this->entry([
                'role' => 'tool_result',
                'toolUseId' => $block['tool_use_id'] ?? null,
                'isError' => ($block['is_error'] ?? false) === true,
                'text' => $isTruncated ? mb_substr($text, 0, self::MAX_TOOL_RESULT_CHARACTERS) : $text,
            ] + ($isTruncated ? ['truncated' => true] : []));
        }

        return $entries;
    }

    private function promptText(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        $parts = array_filter((array) $content, fn (mixed $part): bool => is_array($part) && ($part['type'] ?? null) === 'text');

        return $parts === [] ? null : implode("\n", array_map(fn (array $part): string => (string) ($part['text'] ?? ''), $parts));
    }

    /**
     * Extracts the streamed text of a `stream_event` content block delta. The block key is the
     * message id (or `stream` when the partial carries none) plus the content block index.
     *
     * @param  array<string, mixed>  $line
     * @return array{blockKey: string, kind: string, text: string}|null
     */
    public function delta(array $line): ?array
    {
        if (($line['type'] ?? null) !== 'stream_event') {
            return null;
        }

        $event = (array) ($line['event'] ?? []);
        $delta = (array) ($event['delta'] ?? []);

        [$kind, $text] = match ($delta['type'] ?? null) {
            'text_delta' => ['text', $delta['text'] ?? null],
            'thinking_delta' => ['thinking', $delta['thinking'] ?? null],
            'input_json_delta' => ['tool_input', $delta['partial_json'] ?? null],
            default => [null, null],
        };

        if (($event['type'] ?? null) !== 'content_block_delta' || $kind === null || ! is_string($text) || $text === '') {
            return null;
        }

        $messageId = $line['message_id'] ?? $event['message']['id'] ?? 'stream';

        return ['blockKey' => $messageId . ':' . (int) ($event['index'] ?? 0), 'kind' => $kind, 'text' => $text];
    }

    private function resultText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode("\n", array_map(
            fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : '',
            (array) $content,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, data: array<string, mixed>}
     */
    private function entry(array $data): array
    {
        return ['type' => 'entry', 'data' => $data];
    }
}
