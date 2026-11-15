<?php

namespace App\Services;

use App\Models\Observation;
use App\Services\Threads\ThreadList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The observations list for the web and the app: filtered rows, newest first, and the repositories to filter by.
 */
class ObservationQuery
{
    public const PER_PAGE = 50;

    /**
     * @return LengthAwarePaginator<int, Observation>
     */
    public static function page(?string $repo, ?string $outcome, int $page, ?string $search = null): LengthAwarePaginator
    {
        $pattern = $search !== null && trim($search) !== '' ? ThreadList::likePattern(trim($search)) : null;

        /** @var LengthAwarePaginator<int, Observation> $observations */
        $observations = Observation::query()
            ->when($repo !== null && $repo !== '', fn (Builder $query) => $query->where('repo', $repo))
            ->when(
                in_array($outcome, [Observation::OUTCOME_ACTED, Observation::OUTCOME_DECLINED], true),
                fn (Builder $query) => $query->where('outcome', $outcome),
            )
            ->when($pattern !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw("lower(summary) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(subject) like ? escape '!'", [$pattern])))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return $observations;
    }

    /**
     * @return list<string>
     */
    public static function repos(): array
    {
        /** @var list<string> $repos */
        $repos = Observation::query()
            ->whereNotNull('repo')
            ->distinct()
            ->pluck('repo')
            ->sort()
            ->values()
            ->all();

        return $repos;
    }
}
