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
    private const string PULL_REQUEST_STATE = "coalesce(thread_repositories.pull_request_state, 'open')";

    private const array STATUS_KEYS = ['closed', 'waiting_for_co_owner', 'needs_you', 'stopped', 'working', 'done'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Thread>
     */
    public static function for(User $viewer, array $filters = []): Builder
    {
        $query = self::filtered($viewer, $filters, true)
            ->select('threads.*')
            ->selectRaw(self::pullRequestSubquery('thread_repositories.pull_request_number') . ' as pull_request_number')
            ->selectRaw(self::pullRequestSubquery('thread_repositories.pull_request_url') . ' as pull_request_url')
            ->selectRaw(self::pullRequestSubquery(self::PULL_REQUEST_STATE) . ' as pull_request_state');
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
            $source = ThreadSource::tryFrom((string) $key);
            if ($source === null) {
                continue;
            }
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
                'name' => $user->name ?? (str_starts_with($key, 'name:') ? substr($key, 5) : 'Yak'),
                'avatarUrl' => is_string($avatarUrl) ? $avatarUrl : null,
                'count' => $count,
            ];
        }
        usort($starters, fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $repos = array_values(self::filtered($viewer, [...$filters, 'repo' => ''], false)->where('threads.repo', '!=', Thread::UNKNOWN_REPOSITORY)->distinct()->orderBy('threads.repo')->pluck('threads.repo')->map(fn (mixed $repo): string => (string) $repo)->all());

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
            $query->whereRaw("lower(threads.title) like ? escape '" . ThreadList::LIKE_ESCAPE . "'", [ThreadList::likePattern((string) $filters['search'])]);
        }

        self::pullRequest($query, (string) ($filters['pull_request'] ?? ''));

        return $query;
    }

    /**
     * The one definition of a thread's pull request: its first repository with
     * one, primary before changes. The rows read the same subquery.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function pullRequestSubquery(string $column): string
    {
        return "(select {$column} from thread_repositories where thread_repositories.thread_id = threads.id"
            . " and thread_repositories.role in ('primary', 'changes') and thread_repositories.pull_request_url is not null"
            . " order by case thread_repositories.role when 'primary' then 0 else 1 end, thread_repositories.id limit 1)";
    }

    /**
     * `open`, `merged` and `closed` follow that pull request's state; `held` is no pull request
     * while waiting for a co-owner; `none` is no pull request otherwise. Any other value matches nothing.
     *
     * @param  Builder<Thread>  $query
     */
    private static function pullRequest(Builder $query, string $state): void
    {
        $subquery = self::pullRequestSubquery(self::PULL_REQUEST_STATE);

        match ($state) {
            '' => null,
            'open', 'merged', 'closed' => $query->whereRaw("{$subquery} = ?", [$state]),
            'held' => ThreadRequestStatus::scope($query->whereRaw("{$subquery} is null"), ['waiting_for_co_owner']),
            'none' => $query->whereRaw("{$subquery} is null")->whereNot(fn (Builder $waiting) => ThreadRequestStatus::scope($waiting, ['waiting_for_co_owner'])),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private static function isStarterKey(string $key): bool
    {
        return $key === 'yak' || (str_starts_with($key, 'user:') && ctype_digit(substr($key, 5))) || (str_starts_with($key, 'name:') && strlen($key) > 5);
    }
}
