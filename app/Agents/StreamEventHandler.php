<?php

namespace App\Agents;

use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\TaskLogger;

class StreamEventHandler
{
    private ?TaskLog $pendingToolLog = null;

    private ?string $pendingToolName = null;

    /** @var array<string, mixed>|null */
    private ?array $resultEvent = null;

    /** @var array<string, string> Maps tool_use ID to tool name for correlating tool_result events */
    private array $pendingToolIds = [];

    public function __construct(
        private readonly YakTask $task,
    ) {}

    /**
     * Process a single line of stream-json output.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        $type = $event['type'] ?? '';

        match ($type) {
            'assistant' => $this->handleAssistant($event),
            'tool_use' => $this->handleToolUse($event),
            'tool_result' => $this->handleToolResult($event),
            'result' => $this->handleResult($event),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResultEvent(): ?array
    {
        return $this->resultEvent;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleAssistant(array $event): void
    {
        $message = $event['message'] ?? [];

        if (! is_array($message)) {
            return;
        }

        /** @var array<int, array<string, mixed>> $contentBlocks */
        $contentBlocks = $message['content'] ?? [];

        foreach ($contentBlocks as $block) {
            $blockType = $block['type'] ?? '';

            if ($blockType === 'tool_use') {
                $toolUseEvent = [
                    'name' => $block['name'] ?? 'unknown',
                    'input' => $block['input'] ?? [],
                    'id' => $block['id'] ?? null,
                ];
                $this->handleToolUse($toolUseEvent);

                continue;
            }
        }

        // Extract text content after processing tool_use blocks
        $content = $this->extractAssistantText($event);

        if ($content === '') {
            return;
        }

        // Truncate long assistant messages
        $display = mb_strlen($content) > 500
            ? mb_substr($content, 0, 500) . '…'
            : $content;

        TaskLogger::info($this->task, $display, ['type' => 'assistant']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleToolUse(array $event): void
    {
        $toolName = (string) ($event['tool'] ?? $event['name'] ?? 'unknown');
        /** @var array<string, mixed> $input */
        $input = $event['input'] ?? [];

        $message = $this->formatToolCall($toolName, $input);
        $this->pendingToolName = $toolName;

        $this->pendingToolLog = TaskLogger::info($this->task, $message, [
            'type' => 'tool_use',
            'tool' => $toolName,
            'input' => $this->truncateInput($toolName, $input),
        ]);

        // Track tool ID for correlating with tool_result events
        $toolId = $event['id'] ?? null;
        if ($toolId !== null && $this->pendingToolLog !== null) {
            $this->pendingToolIds[(string) $toolId] = $toolName;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleToolResult(array $event): void
    {
        // Look up pending tool by tool_use_id if no direct pending log
        $toolUseId = $event['tool_use_id'] ?? null;
        if (! $this->pendingToolLog && $toolUseId !== null && isset($this->pendingToolIds[(string) $toolUseId])) {
            unset($this->pendingToolIds[(string) $toolUseId]);
        }

        if (! $this->pendingToolLog) {
            return;
        }

        $output = (string) ($event['content'] ?? $event['output'] ?? '');
        $isError = ($event['is_error'] ?? false) === true;

        /** @var array<string, mixed> $metadata */
        $metadata = $this->pendingToolLog->metadata ?? [];
        $metadata['output'] = $this->truncateOutput($output);
        $metadata['output_lines'] = substr_count($output, "\n") + 1;
        $metadata['is_error'] = $isError;

        // Update message with result summary for bash commands
        $message = $this->pendingToolLog->message;
        if ($this->pendingToolName === 'Bash') {
            $exitCode = $this->extractExitCode($output, $isError);
            $message .= " → exit {$exitCode}";
        }

        $this->pendingToolLog->update([
            'message' => $message,
            'level' => $isError ? 'warning' : 'info',
            'metadata' => $metadata,
        ]);

        $this->pendingToolLog = null;
        $this->pendingToolName = null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleResult(array $event): void
    {
        $this->resultEvent = $event;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function extractAssistantText(array $event): string
    {
        $message = $event['message'] ?? [];

        if (! is_array($message)) {
            return '';
        }

        /** @var array<int, array<string, mixed>> $content */
        $content = $message['content'] ?? [];
        $parts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = trim((string) ($block['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function formatToolCall(string $toolName, array $input): string
    {
        return match ($toolName) {
            'Bash' => $this->formatBash($input),
            'Read' => '📄 Reading `' . ($input['file_path'] ?? 'file') . '`',
            'Edit' => '✏️ Editing `' . ($input['file_path'] ?? 'file') . '`',
            'Write' => '📝 Writing `' . ($input['file_path'] ?? 'file') . '`',
            'Grep' => '🔍 Searching for `' . ($input['pattern'] ?? '...') . '`',
            'Glob' => '📂 Finding files: `' . ($input['pattern'] ?? '*') . '`',
            'Agent' => '🤖 Spawning agent: ' . ($input['description'] ?? 'sub-task'),
            default => str_starts_with($toolName, 'mcp__')
                ? '🔌 MCP: ' . str_replace('mcp__', '', $toolName)
                : "🔧 {$toolName}",
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function formatBash(array $input): string
    {
        $command = (string) ($input['command'] ?? '');
        $description = (string) ($input['description'] ?? '');

        if ($description !== '') {
            return "⚡ {$description}";
        }

        // Truncate long commands
        $display = mb_strlen($command) > 120
            ? mb_substr($command, 0, 120) . '…'
            : $command;

        return "⚡ `{$display}`";
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function truncateInput(string $toolName, array $input): array
    {
        // Don't store full file contents in metadata
        if ($toolName === 'Write' || $toolName === 'Edit') {
            $truncated = $input;
            if (isset($truncated['content']) && mb_strlen((string) $truncated['content']) > 200) {
                $truncated['content'] = mb_substr((string) $truncated['content'], 0, 200) . '… (truncated)';
            }
            if (isset($truncated['new_string']) && mb_strlen((string) $truncated['new_string']) > 200) {
                $truncated['new_string'] = mb_substr((string) $truncated['new_string'], 0, 200) . '… (truncated)';
            }
            if (isset($truncated['old_string']) && mb_strlen((string) $truncated['old_string']) > 200) {
                $truncated['old_string'] = mb_substr((string) $truncated['old_string'], 0, 200) . '… (truncated)';
            }

            return $truncated;
        }

        return $input;
    }

    private function truncateOutput(string $output): string
    {
        $lines = explode("\n", $output);
        $totalLines = count($lines);

        if ($totalLines <= 20) {
            return $output;
        }

        $head = array_slice($lines, 0, 5);
        $tail = array_slice($lines, -5);

        return implode("\n", $head)
            . "\n\n… (" . ($totalLines - 10) . ' lines hidden) …' . "\n\n"
            . implode("\n", $tail);
    }

    private function extractExitCode(string $output, bool $isError): int
    {
        if ($isError) {
            // Try to find exit code in error output
            if (preg_match('/Exit code (\d+)/i', $output, $matches)) {
                return (int) $matches[1];
            }

            return 1;
        }

        return 0;
    }
}
