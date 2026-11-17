<?php

namespace App\Services\Threads;

use App\Enums\ThreadSource;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The thread log: root threads the viewer may see, filtered, counted and
 * sorted in SQL. A filter with only unrecognised values matches nothing.
 *
 * Filters: `status`, `sources`, `starters` (lists), `repo`, `pull_request`
 * (`open`, `merged`, `closed`, `held`, `none`), `needs_co_owner`, `search`,
 * `sort` and `direction`; every key is optional.
 */
final class ThreadLogQuery
{
    private const array STATUS_KEYS = ['closed', 'waiting_for_co_owner', 'needs_you', 'stopped', 'working', 'done'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Thread>
     */
    public static function for(User $viewer, array $filters = []): Builder
    {
        $query = self::filtered($viewer, $filters, true);
        $direction = ($filters['direction'] ?? '') === 'asc' ? 'asc' : 'desc';

        match ($filters['sort'] ?? 'last_activity') {
            'source' => $query->orderBy('threads.source', $direction),
            'starter' => $query->orderByRaw("coalesce((select users.name from users where users.id = threads.created_by_user_id), threads.starter_name, 'Yak') {$direction}"),
            'repo' => $query->orderBy('threads.repo', $direction),
            default => $query->orderByRaw("coalesce(threads.last_activity_at, threads.created_at) {$direction}"),
        };

        return $query->orderByDesc('threads.id');
    }

    /**
     * Threads waiting for a co-owner that the viewer may see, whatever the filters.
     */
    public static function needsCoOwnerCount(User $viewer): int
    {
        return ThreadRequestStatus::scope(self::visible($viewer), ['waiting_for_co_owner'])->count();
    }

    /**
     * The sources, starters and repos among the visible threads, counted under every filter except sources and starters.
     * A picked source or starter without threads is kept with a count of 0.
     *
     * @param  array<string, mixed>  $filters
     * @return array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>, repos: list<string>}
     */
    public static function facets(User $viewer, array $filters = []): array
    {
        $withoutOrigin = self::filtered($viewer, $filters, false);

        $sourceCounts = (clone $withoutOrigin)->selectRaw('threads.source as source_key, count(*) as total')->groupBy('threads.source')->pluck('total', 'source_key')->all();
        foreach ((array) ($filters['sources'] ?? []) as $picked) {
            if (ThreadSource::tryFrom((string) $picked) !== null) {
                $sourceCounts[$picked] ??= 0;
            }
        }
        $sources = [];
        foreach ($sourceCounts as $key => $count) {
            $source = ThreadSource::from((string) $key);
            $sources[] = ['key' => $source->value, 'label' => $source->label(), 'count' => (int) $count];
        }
        usort($sources, fn (array $a, array $b): int => $a['label'] <=> $b['label']);

        $starterRows = (clone $withoutOrigin)->selectRaw('threads.created_by_user_id as user_id, threads.starter_name as starter_name, count(*) as total')
            ->groupBy('threads.created_by_user_id', 'threads.starter_name')->get();
        $starterCounts = [];
        foreach ($starterRows as $row) {
            $key = $row->getAttribute('user_id') !== null ? 'user:' . $row->getAttribute('user_id') : ($row->getAttribute('starter_name') !== null ? 'name:' . $row->getAttribute('starter_name') : 'yak');
            $starterCounts[$key] = ($starterCounts[$key] ?? 0) + (int) $row->getAttribute('total');
        }
        foreach ((array) ($filters['starters'] ?? []) as $picked) {
            if (self::isStarterKey((string) $picked)) {
                $starterCounts[$picked] ??= 0;
            }
        }
        $users = User::query()->whereIn('id', collect(array_keys($starterCounts))->filter(fn (string $key): bool => str_starts_with($key, 'user:'))->map(fn (string $key): int => (int) substr($key, 5))->all())->get()->keyBy('id');
        $starters = [];
        foreach ($starterCounts as $key => $count) {
            $user = str_starts_with($key, 'user:') ? $users->get((int) substr($key, 5)) : null;
            $avatarUrl = $user?->getAttribute('avatar_url');
            $starters[] = [
                'key' => (string) $key,
                'name' => $user?->name ?? (str_starts_with($key, 'name:') ? substr($key, 5) : 'Yak'),
                'avatarUrl' => is_string($avatarUrl) ? $avatarUrl : null,
                'count' => $count,
            ];
        }
        usort($starters, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $repos = self::filtered($viewer, [...$filters, 'repo' => ''], false)->where('threads.repo', '!=', Thread::UNKNOWN_REPOSITORY)->distinct()->orderBy('threads.repo')->pluck('threads.repo')->map(fn (mixed $repo): string => (string) $repo)->all();

        return ['sources' => $sources, 'starters' => $starters, 'repos' => $repos];
    }

    /**
     * @return Builder<Thread>
     */
    private static function visible(User $viewer): Builder
    {
        return Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($viewer)
            ->listed()
            ->whereNull('threads.parent_thread_id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Thread>
     */
    private static function filtered(User $viewer, array $filters, bool $withOrigin): Builder
    {
        $query = self::visible($viewer);

        if ($withOrigin) {
            $sources = array_values((array) ($filters['sources'] ?? []));
            $starters = array_values((array) ($filters['starters'] ?? []));

            if ($sources !== []) {
                $query->whereIn('threads.source', array_map('strval', $sources));
            }

            if ($starters !== []) {
                $known = array_values(array_filter(array_map('strval', $starters), self::isStarterKey(...)));
                $known === [] ? $query->whereRaw('1 = 0') : $query->whereStarters($known);
            }
        }

        $statuses = array_values((array) ($filters['status'] ?? []));
        if ($statuses !== []) {
            $known = array_values(array_intersect(array_map('strval', $statuses), self::STATUS_KEYS));
            $known === [] ? $query->whereRaw('1 = 0') : ThreadRequestStatus::scope($query, $known);
        }

        if (($filters['needs_co_owner'] ?? false) === true) {
            ThreadRequestStatus::scope($query, ['waiting_for_co_owner']);
        }

        if (($filters['repo'] ?? '') !== '') {
            $query->where('threads.repo', $filters['repo']);
        }

        if (($filters['search'] ?? '') !== '') {
            $query->whereRaw("lower(threads.title) like ? escape '!'", [ThreadList::likePattern((string) $filters['search'])]);
        }

        self::pullRequest($query, (string) ($filters['pull_request'] ?? ''));

        return $query;
    }

    /**
     * @param  Builder<Thread>  $query
     */
    private static function pullRequest(Builder $query, string $state): void
    {
        $hasPullRequest = fn (?string $stateName) => fn (Builder $threads) => $threads->whereExists(fn ($repository) => $repository->from('thread_repositories')->selectRaw('1')
            ->whereColumn('thread_repositories.thread_id', 'threads.id')
            ->whereIn('thread_repositories.role', ['primary', 'changes'])
            ->whereNotNull('thread_repositories.pull_request_url')
            ->when($stateName === 'open', fn ($open) => $open->where(fn ($either) => $either->whereNull('thread_repositories.pull_request_state')->orWhere('thread_repositories.pull_request_state', 'open')))
            ->when($stateName !== null && $stateName !== 'open', fn ($other) => $other->where('thread_repositories.pull_request_state', $stateName)));

        match ($state) {
            'open', 'merged', 'closed' => $query->where($hasPullRequest($state)),
            'none' => $query->whereNot($hasPullRequest(null)),
            'held' => ThreadRequestStatus::scope($query, ['waiting_for_co_owner']),
            default => null,
        };
    }

    private static function isStarterKey(string $key): bool
    {
        return $key === 'yak' || (str_starts_with($key, 'user:') && ctype_digit(substr($key, 5))) || (str_starts_with($key, 'name:') && strlen($key) > 5);
    }
}
