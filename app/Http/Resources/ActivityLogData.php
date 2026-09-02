<?php

namespace App\Http\Resources;

use App\Models\TaskLog;
use App\Models\YakTask;
use App\Support\Markdown;
use Illuminate\Support\Collection;

/**
 * Flattens a run's {@see TaskLog} rows into the Activity card's row shape.
 * Consecutive info-level assistant "thinking" entries are tagged with a
 * shared `group` index instead of being collapsed server-side, so the
 * React page can toggle grouped/ungrouped (the old "Detailed" view) without
 * a round trip.
 */
final class ActivityLogData
{
    /**
     * @param  Collection<int, TaskLog>  $logs
     * @return array{entries: int, duration: string, rows: array<int, array<string, mixed>>}
     */
    public static function build(Collection $logs, YakTask $run, bool $isActiveStatus): array
    {
        return [
            'entries' => $logs->count(),
            'duration' => self::formatDuration($run->duration_ms),
            'rows' => self::rows($logs, $isActiveStatus),
        ];
    }

    /**
     * @param  Collection<int, TaskLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    private static function rows(Collection $logs, bool $isActiveStatus): array
    {
        $rows = [];
        $groupIndex = -1;
        $inGroup = false;

        foreach ($logs as $log) {
            /** @var array<string, mixed>|null $metadata */
            $metadata = $log->metadata;
            $type = $metadata['type'] ?? null;
            $isGroupable = $type === 'assistant' && $log->level === 'info';

            if ($isGroupable) {
                if (! $inGroup) {
                    $groupIndex++;
                    $inGroup = true;
                }
            } else {
                $inGroup = false;
            }

            $rows[] = self::row($log, $isGroupable ? $groupIndex : null, $isActiveStatus);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(TaskLog $log, ?int $group, bool $isActiveStatus): array
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
            'group' => $group,
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
