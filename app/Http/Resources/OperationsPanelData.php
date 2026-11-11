<?php

namespace App\Http\Resources;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Observation;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Services\Threads\ThreadList;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Operations panel's live counts and repository list. Thread-derived data
 * comes only from {@see ThreadList::for()}, so another user's private thread is
 * never counted in a repository's status line.
 */
final class OperationsPanelData
{
    private const int REPOSITORY_LIMIT = 8;

    /**
     * @return array{
     *     counts: array{repositories: int, deployments: int, prReviews: int, observations: int},
     *     deploymentsRunning: int,
     *     prReviewsOpen: int,
     *     observationsNew: int,
     *     repositories: list<array{slug: string, initials: string, status: string}>,
     * }
     */
    public static function for(User $user): array
    {
        $roots = collect(ThreadList::for($user))->only(['needsYou', 'threads'])->flatten(1);

        $repositories = array_values(Repository::query()
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(self::REPOSITORY_LIMIT)
            ->get(['slug', 'name'])
            ->map(fn (Repository $repository): array => [
                'slug' => $repository->slug,
                'initials' => self::initials($repository->name),
                'status' => self::status($roots->where('repo', $repository->slug)),
            ])
            ->all());

        return [
            'counts' => [
                'repositories' => Repository::query()->count(),
                'deployments' => BranchDeployment::query()->whereIn('status', [
                    DeploymentStatus::Pending->value,
                    DeploymentStatus::Starting->value,
                    DeploymentStatus::Running->value,
                    DeploymentStatus::Hibernated->value,
                    DeploymentStatus::Failed->value,
                ])->count(),
                'prReviews' => PrReview::query()->count(),
                'observations' => Observation::query()->count(),
            ],
            'deploymentsRunning' => BranchDeployment::query()->where('status', DeploymentStatus::Running->value)->count(),
            'prReviewsOpen' => PrReview::query()->whereNull('dismissed_at')->count(),
            'observationsNew' => Observation::query()->where('created_at', '>=', now()->subDay())->count(),
            'repositories' => $repositories,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private static function status($rows): string
    {
        $needsYou = $rows->whereNotNull('needsYouReason')->count();
        $running = $rows->where('status', 'running')->count();

        return match (true) {
            $needsYou > 0 => $needsYou . ' needs you',
            $running > 0 => $running . ($running === 1 ? ' thread running' : ' threads running'),
            default => 'Quiet',
        };
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/[\s\-_\/]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = count($words) > 1 ? $words[0][0] . $words[1][0] : Str::substr($name, 0, 2);

        return Str::upper($initials);
    }
}
