<?php

namespace App\Http\Resources;

use App\Models\Repository;

/**
 * Flattens a {@see Repository} into the plain array shape the repositories
 * index table renders. Business logic ported from the deleted RepoList
 * component.
 */
final class RepositorySummaryData
{
    /**
     * @return array{
     *     slug: string,
     *     name: string,
     *     ciLabel: string,
     *     setupStatus: string,
     *     sandboxBaseVersion: ?int,
     *     currentBaseVersion: int,
     *     isActive: bool,
     *     isDefault: bool,
     *     tasksTotal: int,
     *     tasks7d: int,
     *     prReviewEnabled: bool,
     *     prReviews30d: int,
     * }
     */
    public static function from(Repository $repository, int $prReviews30d): array
    {
        return [
            'slug' => $repository->slug,
            'name' => $repository->name,
            'ciLabel' => self::ciSystemLabel($repository->ci_system),
            'setupStatus' => $repository->setup_status,
            'sandboxBaseVersion' => $repository->sandbox_base_version,
            'currentBaseVersion' => (int) config('yak.sandbox.base_version', 1),
            'isActive' => $repository->is_active,
            'isDefault' => $repository->is_default,
            'tasksTotal' => (int) $repository->tasks_count,
            'tasks7d' => (int) $repository->tasks_recent_count,
            'prReviewEnabled' => (bool) $repository->pr_review_enabled,
            'prReviews30d' => $prReviews30d,
        ];
    }

    public static function ciSystemLabel(string $ciSystem): string
    {
        return match ($ciSystem) {
            'github_actions' => 'GitHub Actions',
            'drone' => 'Drone',
            default => ucfirst($ciSystem),
        };
    }
}
