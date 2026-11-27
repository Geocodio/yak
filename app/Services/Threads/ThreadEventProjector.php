<?php

namespace App\Services\Threads;

use App\Agents\ClaudeCodeOutputParser;
use App\Support\Markdown;

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
     * @param  bool  $tagsAgents  whether a line an agent wrote is tagged with its agentId
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function project(array $line, bool $hasOpenBlock = false, bool $tagsAgents = true): array
    {
        $entries = $this->projectLine($line, $hasOpenBlock);
        $agentId = $line['agent_id'] ?? $line['parent_tool_use_id'] ?? null;

        // A line an agent wrote is tagged with the agent, so Claude's own transcript can leave it out.
        if (! $tagsAgents || ! is_string($agentId) || $agentId === '' || ! in_array($line['type'] ?? null, ['assistant', 'user'], true)) {
            return $entries;
        }

        return array_map(fn (array $entry): array => $entry['type'] === 'entry' ? [...$entry, 'data' => [...$entry['data'], 'agentId' => $agentId]] : $entry, $entries);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function projectLine(array $line, bool $hasOpenBlock): array
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
            'result' => [$this->entry($this->withHtml([
                'role' => 'result',
                'subtype' => $line['subtype'] ?? null,
                'isError' => ($line['is_error'] ?? false) === true,
                'text' => is_string($line['result'] ?? null) ? $line['result'] : null,
                'costUsd' => $line['total_cost_usd'] ?? null,
                'durationMs' => $line['duration_ms'] ?? null,
                'numTurns' => $line['num_turns'] ?? null,
            ]))],
            default => [],
        };
    }

    /**
     * Adds server-rendered Markdown (`html`) to the text of a result entry and of each assistant
     * text block, so the page shows formatted text. Entries stored before `html` existed get it
     * here when they are read back.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withHtml(array $data): array
    {
        if (($data['role'] ?? null) === 'result' && is_string($data['text'] ?? null)) {
            $data['text'] = self::withoutQuestionsBlock($data['text']);
            $data['html'] = Markdown::toHtml($data['text']);
        }

        if (($data['role'] ?? null) === 'assistant') {
            $data['blocks'] = array_map(
                fn (mixed $block): mixed => is_array($block) && ($block['type'] ?? null) === 'text' && ! isset($block['html'])
                    ? self::withHtmlBlock($block)
                    : $block,
                (array) ($data['blocks'] ?? []),
            );
        }

        return $data;
    }

    /**
     * The text without its clarification block when the block parses as questions, which the page shows as a form.
     */
    private static function withoutQuestionsBlock(string $text): string
    {
        return ClaudeCodeOutputParser::extractClarificationQuestions($text) === [] ? $text : ClaudeCodeOutputParser::stripClarificationBlock($text);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function withHtmlBlock(array $block): array
    {
        $text = self::withoutQuestionsBlock((string) ($block['text'] ?? ''));

        return array_merge($block, ['text' => $text, 'html' => Markdown::toHtml($text)]);
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
                'text' => ['type' => 'text', 'text' => (string) ($block['text'] ?? '')],
                'thinking' => ['type' => 'thinking', 'text' => (string) ($block['thinking'] ?? '')],
                'tool_use' => ['type' => 'tool_use', 'id' => $block['id'] ?? null, 'name' => $block['name'] ?? null, 'input' => $block['input'] ?? (object) []],
                default => null,
            };
        }

        $blocks = array_values(array_filter($blocks));

        return $blocks === [] ? [] : [$this->entry($this->withHtml(['role' => 'assistant', 'messageId' => $message['id'] ?? null, 'blocks' => $blocks]))];
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
            // The CLI writes this marker for every message an interrupt dropped; it is not something the person typed.
            if (str_starts_with(trim($text), '[Request interrupted by user')) {
                return [];
            }

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
     * The type, content index and message id of a `stream_event` line, or null for any other line
     * and for one an agent wrote. The message id comes from `api_message_id`, which every stream
     * line carries; a `message_start` also names it inside the event.
     *
     * @param  array<string, mixed>  $line
     * @return array{type: string, index: int, messageId: ?string}|null
     */
    public function streamEvent(array $line): ?array
    {
        if (($line['type'] ?? null) !== 'stream_event' || ($line['parent_tool_use_id'] ?? null) !== null || ($line['agent_id'] ?? null) !== null) {
            return null;
        }

        $event = (array) ($line['event'] ?? []);
        $messageId = $line['api_message_id'] ?? $event['message']['id'] ?? null;

        return [
            'type' => (string) ($event['type'] ?? ''),
            'index' => (int) ($event['index'] ?? 0),
            'messageId' => is_string($messageId) ? $messageId : null,
        ];
    }

    /**
     * Extracts the streamed text of a `stream_event` content block delta.
     *
     * @param  array<string, mixed>  $line
     * @return array{index: int, kind: string, text: string}|null
     */
    public function delta(array $line): ?array
    {
        $streamEvent = $this->streamEvent($line);

        if ($streamEvent === null || $streamEvent['type'] !== 'content_block_delta') {
            return null;
        }

        $delta = (array) ($line['event']['delta'] ?? []);

        [$kind, $text] = match ($delta['type'] ?? null) {
            'text_delta' => ['text', $delta['text'] ?? null],
            'thinking_delta' => ['thinking', $delta['thinking'] ?? null],
            'input_json_delta' => ['tool_input', $delta['partial_json'] ?? null],
            default => [null, null],
        };

        if ($kind === null || ! is_string($text) || $text === '') {
            return null;
        }

        return ['index' => $streamEvent['index'], 'kind' => $kind, 'text' => $text];
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
