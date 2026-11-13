<?php

namespace App\Http\Resources;

use App\Models\TaskLog;
use App\Models\YakTask;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Flattens a run's {@see TaskLog} rows into the Activity card's row shape,
 * windowed by cursor rather than loading a whole run at once. Grouping
 * consecutive "thinking" entries is a client concern; rows carry no group.
 */
final class ActivityLogData
{
    public const WINDOW = 200;

    /** Tool names the stream knows how to label; anything else reads as a plain command. */
    private const TOOL_CATEGORIES = [
        'Bash' => 'command',
        'Read' => 'read',
        'Edit' => 'edit',
        'Write' => 'edit',
        'Grep' => 'search',
        'Glob' => 'search',
        'WebFetch' => 'fetch',
        'WebSearch' => 'fetch',
        'Task' => 'command',
        'Agent' => 'command',
    ];

    /**
     * Totals for the whole attempt, so the folded groups and the finished
     * one-liner stay right outside the loaded window and past the row cap.
     *
     * @return array{entries: int, duration: string, latestId: int|null, counts: array{command: int, read: int, edit: int, search: int, fetch: int, thinking: int}, failed: int}
     */
    public static function summary(YakTask $run, int $attempt): array
    {
        $query = self::attemptLogs($run, $attempt);

        $counts = ['command' => 0, 'read' => 0, 'edit' => 0, 'search' => 0, 'fetch' => 0, 'thinking' => 0];

        // Only the tool name leaves the database, not the metadata blob, since the poll asks for this on every tick.
        foreach ((clone $query)->where('metadata->type', 'tool_use')->select('metadata->tool as tool')->get() as $log) {
            $counts[self::TOOL_CATEGORIES[(string) $log->getAttribute('tool')] ?? 'command']++;
        }

        $counts['thinking'] = (clone $query)->where('metadata->thinking', true)->count();

        return [
            'entries' => (clone $query)->count(),
            'duration' => self::formatDuration($run->duration_ms),
            'latestId' => (clone $query)->max('id'),
            'counts' => $counts,
            'failed' => (clone $query)->where('metadata->is_error', true)->count(),
        ];
    }

    /**
     * The newest WINDOW rows, oldest first, with the cursor the client uses to
     * ask for the rows before them.
     *
     * @return array{rows: array<int, array<string, mixed>>, oldestId: int|null, hasOlder: bool}
     */
    public static function window(YakTask $run, int $attempt): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = self::attemptLogs($run, $attempt)->orderByDesc('id')->limit(self::WINDOW + 1)->get();
        $hasOlder = $logs->count() > self::WINDOW;
        $logs = $logs->take(self::WINDOW)->reverse()->values();

        return [
            'rows' => self::rows($logs),
            'oldestId' => $logs->first()?->id,
            'hasOlder' => $hasOlder,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function before(YakTask $run, int $attempt, int $beforeId): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = self::attemptLogs($run, $attempt)->where('id', '<', $beforeId)->orderByDesc('id')->limit(self::WINDOW)->get();

        return self::rows($logs->reverse()->values());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function after(YakTask $run, int $attempt, int $afterId): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = self::attemptLogs($run, $attempt)->where('id', '>', $afterId)->orderBy('id')->limit(self::WINDOW)->get();

        return self::rows($logs);
    }

    /**
     * A later identical command that passed after a failed one points back at
     * it through `retryOf`, so the stream can label the pair.
     *
     * @param  Collection<int, TaskLog>  $logs  oldest first
     * @return array<int, array<string, mixed>>
     */
    private static function rows(Collection $logs): array
    {
        $lastFailedByCommand = [];
        $rows = [];

        foreach ($logs as $log) {
            $row = self::row($log);

            if ($row['tool'] !== null && $row['category'] !== 'milestone') {
                $key = $row['tool'] . ':' . $row['label'];

                if ($row['error']) {
                    $lastFailedByCommand[$key] = $row['id'];
                } elseif (! $row['running'] && isset($lastFailedByCommand[$key])) {
                    $row['retryOf'] = $lastFailedByCommand[$key];
                    unset($lastFailedByCommand[$key]);
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return HasMany<TaskLog, YakTask>
     */
    private static function attemptLogs(YakTask $run, int $attempt): HasMany
    {
        return $run->logs()->where('attempt_number', $attempt);
    }

    /**
     * `at` is always the absolute time; the client derives relative ages
     * from `createdAt`, since rows stay in its memory while time moves on.
     *
     * @return array{id: int, badge: string|null, text: string, at: string, createdAt: string, kind: string, error: bool, milestone: bool, tool: string|null, category: string, label: string, durationMs: int|null, exitCode: int|null, running: bool, retryOf: int|null}
     */
    private static function row(TaskLog $log): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = (array) $log->metadata;
        $type = $metadata['type'] ?? null;
        $isToolUse = $type === 'tool_use';

        [$kind, $badge] = match (true) {
            $isToolUse => ['tool', (string) ($metadata['tool'] ?? 'tool')],
            $type === 'prompt' => ['prompt', 'prompt'],
            $type === 'assistant' => ['assistant', null],
            default => ['level', $log->level],
        };

        $toolName = $isToolUse ? (string) ($metadata['tool'] ?? '') : '';
        $tool = match ($toolName) {
            'Bash', 'Read', 'Edit', 'Write', 'Grep', 'Glob', 'WebFetch' => $toolName,
            'Task', 'Agent' => 'Task',
            default => null,
        };

        $category = match (true) {
            $isToolUse => self::TOOL_CATEGORIES[$toolName] ?? 'command',
            $type === 'assistant' => ($metadata['thinking'] ?? false) === true ? 'thinking' : 'narrative',
            default => 'milestone',
        };

        $isFinished = $isToolUse && (array_key_exists('output', $metadata) || array_key_exists('is_error', $metadata));
        $isError = (bool) ($metadata['is_error'] ?? false);

        return [
            'id' => $log->id,
            'badge' => $badge,
            'text' => Markdown::toPlainText($log->message),
            'at' => $log->created_at->format('g:i:s A'),
            'createdAt' => $log->created_at->toIso8601String(),
            'kind' => $kind,
            'error' => $isError,
            'milestone' => self::isMilestone($log),
            'tool' => $tool,
            'category' => $category,
            'label' => $isToolUse ? self::toolLabel($toolName, (array) ($metadata['input'] ?? []), $log->message) : Markdown::toPlainText($log->message),
            'durationMs' => isset($metadata['duration_ms']) ? (int) $metadata['duration_ms'] : null,
            'exitCode' => $isFinished && $toolName === 'Bash' ? self::exitCode($log->message, $metadata, $isError) : null,
            'running' => $isToolUse && ! $isFinished,
            'retryOf' => null,
        ];
    }

    /**
     * What a tool row shows on its line: the command, path, pattern or URL it
     * acted on, without the emoji and exit suffix the message carries.
     *
     * @param  array<string, mixed>  $input
     */
    private static function toolLabel(string $toolName, array $input, string $message): string
    {
        $label = match ($toolName) {
            'Bash' => (string) ($input['command'] ?? ''),
            'Read', 'Edit', 'Write' => (string) ($input['file_path'] ?? ''),
            'Grep', 'Glob' => (string) ($input['pattern'] ?? ''),
            'WebFetch' => (string) ($input['url'] ?? ''),
            'Task', 'Agent' => (string) ($input['description'] ?? ''),
            default => '',
        };

        $label = $label !== '' ? $label : trim((string) preg_replace('/ → exit \d+$/u', '', Markdown::toPlainText($message)));

        return mb_strlen($label) > 300 ? mb_substr($label, 0, 300) . '…' : $label;
    }

    /**
     * Rows written before `exit_code` was stored carry it only in the
     * message suffix ("... → exit 2"); without either, the error flag decides.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function exitCode(string $message, array $metadata, bool $isError): int
    {
        if (isset($metadata['exit_code'])) {
            return (int) $metadata['exit_code'];
        }

        if (preg_match('/→ exit (\d+)$/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return $isError ? 1 : 0;
    }

    public static function isMilestone(TaskLog $log): bool
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $log->metadata;
        $type = $metadata['type'] ?? null;

        if ($type !== 'tool_use' && $type !== 'assistant') {
            return true;
        }

        return in_array($log->level, ['error', 'warning'], true);
    }

    private static function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null || $durationMs === 0) {
            return '—';
        }

        $minutes = (int) round($durationMs / 60000);

        if ($minutes < 1) {
            return '1m';
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }
}
