<?php

namespace App\Http\Resources;

use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\YakTask;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Flattens a {@see YakTask} (and its follow-up chain) into the plain array
 * shape the Tasks index page renders. Business logic ported from the
 * deleted TaskList component.
 */
final class TaskRowData
{
    /**
     * @param  array{children?: Collection<int, YakTask>, preview?: array{poster?: ?string, gif?: ?string}|null, deployment?: ?BranchDeployment}  $ctx
     * @return array{
     *     id: int,
     *     status: string,
     *     source: string,
     *     sourceLabel: string,
     *     by: ?string,
     *     repo: ?string,
     *     repoUrl: ?string,
     *     description: string,
     *     externalId: ?string,
     *     externalUrl: ?string,
     *     pr: ?array{number: ?int, state: string, url: ?string},
     *     previewUrl: ?string,
     *     previewGif: ?string,
     *     deploymentUrl: ?string,
     *     cost: ?string,
     *     createdAgo: string,
     *     createdAt: ?string,
     *     createdTooltip: string,
     *     followUps: array<int, array<string, mixed>>,
     * }
     */
    public static function from(YakTask $task, array $ctx = []): array
    {
        /** @var Collection<int, YakTask> $children */
        $children = $ctx['children'] ?? collect();
        $preview = $ctx['preview'] ?? null;
        $deployment = $ctx['deployment'] ?? null;

        // A follow-up chain's most recent activity is more relevant than the
        // root's own (stale) status, so the row reflects the latest child
        // when any exist.
        $latest = $children->last();
        $effective = $latest instanceof YakTask ? $latest : $task;

        $prState = $task->prState();

        return [
            'id' => $task->id,
            'status' => $effective->status->value,
            'source' => $task->source ?? 'manual',
            'sourceLabel' => ucfirst($task->source ?? 'manual'),
            'by' => $task->author_name,
            'repo' => $task->repo,
            'repoUrl' => self::repoUrl($task),
            'description' => Str::limit((string) $task->description, 140),
            'externalId' => $task->external_id,
            'externalUrl' => $task->external_url,
            'pr' => $prState === null ? null : [
                'number' => $task->pr_number,
                'state' => $prState,
                'url' => $task->pr_url,
            ],
            'previewUrl' => $preview['poster'] ?? null,
            'previewGif' => $preview['gif'] ?? null,
            'deploymentUrl' => $deployment instanceof BranchDeployment ? 'https://' . $deployment->hostname : null,
            'cost' => self::formatCost($task->cost_usd),
            'createdAgo' => self::formatAge($effective->created_at),
            'createdAt' => $task->created_at?->toIso8601String(),
            'createdTooltip' => sprintf(
                'Created %s · Updated %s · Ran for %s',
                $task->created_at?->format('M j, Y H:i') ?? '—',
                $task->updated_at?->format('M j, Y H:i') ?? '—',
                self::formatDuration($task->duration_ms),
            ),
            'followUps' => $children->map(fn (YakTask $child): array => [
                'id' => $child->id,
                'status' => $child->status->value,
                'description' => Str::limit((string) $child->description, 140),
                'externalId' => $child->external_id,
                'createdAgo' => self::formatAge($child->created_at),
            ])->values()->all(),
        ];
    }

    private static function repoUrl(YakTask $task): ?string
    {
        if ($task->pr_url !== null && $task->pr_url !== '') {
            return $task->pr_url;
        }

        if ($task->repo === null) {
            return null;
        }

        $repository = $task->relationLoaded('repository')
            ? $task->repository
            : Repository::query()->where('slug', $task->repo)->first();

        return $repository === null ? null : route('repos.edit', $repository);
    }

    private static function formatCost(mixed $costUsd): ?string
    {
        $value = (float) $costUsd;

        if ($value <= 0.0) {
            return null;
        }

        return '$' . number_format($value, 2);
    }

    /**
     * Relative age shown in the table, e.g. "3h ago". The tooltip carries
     * the absolute timestamps.
     */
    private static function formatAge(?CarbonInterface $timestamp): string
    {
        if ($timestamp === null) {
            return '—';
        }

        return $timestamp->diffForHumans(['short' => true, 'parts' => 1]);
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
