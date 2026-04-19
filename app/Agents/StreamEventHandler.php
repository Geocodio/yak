<?php

namespace App\Agents;

use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\TaskLogger;

class StreamEventHandler
{
    private ?TaskLog $pendingToolLog = null;

    private ?string $pendingToolName = null;

    /**
     * Original message for the in-flight tool log, captured the first
     * time heartbeat() appends a duration suffix so repeated heartbeats
     * rewrite rather than accumulate "(1m) (2m) (3m)".
     */
    private ?string $pendingToolBaseMessage = null;

    /** @var array<string, mixed>|null */
    private ?array $resultEvent = null;

    /** @var array<string, string> Maps tool_use ID to tool name for correlating tool_result events */
    private array $pendingToolIds = [];

    public function __construct(
        private readonly YakTask $task,
    ) {}

    /**
     * Called from the stream loop when Claude has been silent for a
     * while but the exec process is still alive (typically during long
     * `Bash` calls like `docker build`). Two jobs:
     *
     *   1. Touch the task so `yak:reap-orphaned-tasks` doesn't consider
     *      it orphaned — its SQL filter is `updated_at < threshold`, so
     *      a heartbeat keeps it out of the candidate set.
     *   2. Update the in-flight tool_use log's message with an elapsed
     *      duration so the task detail UI reflects that work is still
     *      happening. No new log rows are created.
     */
    public function heartbeat(int $idleSeconds): void
    {
        $this->task->touch();

        if ($this->pendingToolLog === null) {
            return;
        }

        if ($this->pendingToolBaseMessage === null) {
            $this->pendingToolBaseMessage = $this->pendingToolLog->message;
        }

        $this->pendingToolLog->update([
            'message' => $this->pendingToolBaseMessage . ' ' . $this->formatElapsed($idleSeconds),
        ]);
    }

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
        $this->pendingToolBaseMessage = null;

        // Track tool ID for correlating with tool_result events
        $toolId = $event['id'] ?? null;
        if ($toolId !== null) {
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

        // Strip any heartbeat duration suffix before appending the exit
        // summary so the final message reads "⚡ cmd → exit 0", not
        // "⚡ cmd (3m) → exit 0".
        $message = $this->pendingToolBaseMessage ?? $this->pendingToolLog->message;
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
        $this->pendingToolBaseMessage = null;
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
            'TodoWrite' => $this->formatTodoWrite($input),
            'ToolSearch' => '🔎 Tool search: ' . $this->truncate((string) ($input['query'] ?? ''), 80),
            'WebFetch' => '🌐 Fetching ' . ($input['url'] ?? 'url'),
            'WebSearch' => '🔎 Web search: ' . $this->truncate((string) ($input['query'] ?? ''), 80),
            'NotebookEdit' => '✏️ Editing notebook `' . ($input['notebook_path'] ?? 'notebook') . '`',
            default => str_starts_with($toolName, 'mcp__')
                ? $this->formatMcpCall($toolName, $input)
                : "🔧 {$toolName}",
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function formatTodoWrite(array $input): string
    {
        /** @var array<int, array<string, mixed>>|mixed $todos */
        $todos = $input['todos'] ?? [];

        if (! is_array($todos)) {
            return '✅ TodoWrite';
        }

        foreach ($todos as $todo) {
            if (! is_array($todo)) {
                continue;
            }
            if (($todo['status'] ?? '') === 'in_progress') {
                $activeForm = trim((string) ($todo['activeForm'] ?? ''));
                if ($activeForm !== '') {
                    return '✅ ' . $activeForm;
                }
            }
        }

        $count = count($todos);

        return '✅ Updated ' . $count . ' todo' . ($count === 1 ? '' : 's');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function formatMcpCall(string $toolName, array $input): string
    {
        $stripped = substr($toolName, 5);
        $pos = strpos($stripped, '__');
        if ($pos === false) {
            $server = $stripped;
            $method = '';
        } else {
            $server = substr($stripped, 0, $pos);
            $method = substr($stripped, $pos + 2);
        }

        if (str_starts_with($server, 'claude_ai_')) {
            $server = strtolower(substr($server, 10));
        }

        $summary = $this->summarizeMcpParams($input);

        if ($method === '') {
            return $summary === '' ? "🔌 {$server}" : "🔌 {$server}({$summary})";
        }

        return $summary === '' ? "🔌 {$server}: {$method}" : "🔌 {$server}: {$method}({$summary})";
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function summarizeMcpParams(array $input): string
    {
        $priority = ['query', 'body', 'text', 'message', 'prompt', 'url', 'path', 'file_path', 'issueId', 'id'];

        $picked = [];
        foreach ($priority as $key) {
            if (array_key_exists($key, $input) && $this->isDisplayableScalar($input[$key])) {
                $picked[$key] = $input[$key];
                if (count($picked) === 2) {
                    break;
                }
            }
        }

        if (count($picked) < 2) {
            foreach ($input as $key => $value) {
                if (array_key_exists($key, $picked)) {
                    continue;
                }
                if ($this->isDisplayableScalar($value)) {
                    $picked[$key] = $value;
                    if (count($picked) === 2) {
                        break;
                    }
                }
            }
        }

        $parts = [];
        foreach ($picked as $key => $value) {
            if (is_string($value)) {
                $collapsed = preg_replace('/\s+/', ' ', trim($value)) ?? '';
                $display = $this->truncate($collapsed, 60);
                $parts[] = "{$key}: \"{$display}\"";
            } elseif (is_bool($value)) {
                $parts[] = "{$key}: " . ($value ? 'true' : 'false');
            } else {
                $parts[] = "{$key}: {$value}";
            }
        }

        return implode(', ', $parts);
    }

    private function isDisplayableScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_bool($value);
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
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

    private function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return "({$seconds}s)";
        }

        $minutes = intdiv($seconds, 60);

        return "({$minutes}m)";
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
