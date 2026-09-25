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

    /**
     * @return array{entries: int, duration: string, latestId: int|null}
     */
    public static function summary(YakTask $run, int $attempt): array
    {
        $query = self::attemptLogs($run, $attempt);

        return [
            'entries' => (clone $query)->count(),
            'duration' => self::formatDuration($run->duration_ms),
            'latestId' => (clone $query)->max('id'),
        ];
    }

    /**
     * The newest WINDOW rows, oldest first, with the cursor the client uses to
     * ask for the rows before them.
     *
     * @return array{rows: array<int, array<string, mixed>>, oldestId: int|null, hasOlder: bool}
     */
    public static function window(YakTask $run, int $attempt, bool $isActiveStatus): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = self::attemptLogs($run, $attempt)->orderByDesc('id')->limit(self::WINDOW + 1)->get();
        $hasOlder = $logs->count() > self::WINDOW;
        $logs = $logs->take(self::WINDOW)->reverse()->values();

        return [
            'rows' => $logs->map(fn (TaskLog $log): array => self::row($log, $isActiveStatus))->all(),
            'oldestId' => $logs->first()?->id,
            'hasOlder' => $hasOlder,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function before(YakTask $run, int $attempt, int $beforeId, bool $isActiveStatus): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = self::attemptLogs($run, $attempt)->where('id', '<', $beforeId)->orderByDesc('id')->limit(self::WINDOW)->get();

        return $logs->reverse()->values()->map(fn (TaskLog $log): array => self::row($log, $isActiveStatus))->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function after(YakTask $run, int $attempt, int $afterId, bool $isActiveStatus): array
    {
        /** @var Collection<int, TaskLog> $logs */
        $logs = self::attemptLogs($run, $attempt)->where('id', '>', $afterId)->orderBy('id')->limit(self::WINDOW)->get();

        return $logs->map(fn (TaskLog $log): array => self::row($log, $isActiveStatus))->all();
    }

    /**
     * @return HasMany<TaskLog, YakTask>
     */
    private static function attemptLogs(YakTask $run, int $attempt): HasMany
    {
        return $run->logs()->where('attempt_number', $attempt);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(TaskLog $log, bool $isActiveStatus): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = (array) $log->metadata;
        $type = $metadata['type'] ?? null;

        [$kind, $badge] = match (true) {
            $type === 'tool_use' => ['tool', (string) ($metadata['tool'] ?? 'tool')],
            $type === 'prompt' => ['prompt', 'prompt'],
            $type === 'assistant' => ['assistant', null],
            default => ['level', $log->level],
        };

        return [
            'id' => $log->id,
            'badge' => $badge,
            'text' => Markdown::toPlainText($log->message),
            'at' => $isActiveStatus
                ? $log->created_at->diffForHumans()
                : $log->created_at->format('g:i:s A'),
            'kind' => $kind,
            'error' => (bool) ($metadata['is_error'] ?? false),
            'milestone' => self::isMilestone($log),
        ];
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
