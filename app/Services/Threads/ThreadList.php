<?php

namespace App\Services\Threads;

use App\Enums\NeedsYouReason;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadListFilter;
use App\Enums\ThreadListStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSource;
use App\Enums\ThreadVisibility;
use App\Enums\TurnState;
use App\Http\Resources\ThreadOriginData;
use App\Models\Scopes\VisibleThreadScope;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadStar;
use App\Models\ThreadView;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The sidebar's thread list: open root threads the viewer may see (closed ones
 * under the Closed filter), the viewer's starred ones pinned first, then those
 * that need the viewer, each with its sub-threads nested underneath.
 *
 * A row is `array{id: string, title: string, repo: string, status: string,
 * needsYouReason: ?string, needsYouLabel: ?string, isPrivate: bool, isShared: bool,
 * isUnattended: bool, isStarred: bool, canDelete: bool, isUnread: bool, ownerInitials: ?string,
 * source: array{key: string, label: string, reference: ?string, url: ?string}, starter: array{key: string, name: string, avatarUrl: ?string}, lastActivityAt: ?string, group: 'today'|'this_week'|'older',
 * children: list<array<string, mixed>>}`.
 *
 * The facets count the tab's rows; a picked source or starter without rows is appended with count 0.
 */
class ThreadList
{
    private const LIKE_ESCAPE = '!';

    /**
     * @param  list<string>  $sources
     * @param  list<string>  $starters
     * @return array{starred: list<array<string, mixed>>, needsYou: list<array<string, mixed>>, threads: list<array<string, mixed>>, needsYouCount: int, hasAnyThreads: bool, facets: array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>}}
     */
    public static function for(User $user, ThreadListFilter $filter = ThreadListFilter::Mine, ?string $search = null, array $sources = [], array $starters = []): array
    {
        $status = $filter === ThreadListFilter::Closed ? 'closed' : 'open';
        $searchText = trim($search ?? '');
        $roots = Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($user)
            ->listed()
            ->whereNull('parent_thread_id')
            ->where('status', $status)
            ->when($filter === ThreadListFilter::Mine, fn (Builder $query) => self::mine($query, $user))
            ->when($filter === ThreadListFilter::StartedByYak, fn (Builder $query) => $query->whereNull('created_by_user_id'))
            ->when($searchText !== '', fn (Builder $query) => self::matching($query, $user, $status, $searchText))
            ->select('threads.*')
            ->addSelect(['latest_run_id' => self::latestRunId()])
            ->withExists('shares as is_shared')
            ->with([
                'creator:id,name,avatar_url',
                'children.creator:id,name,avatar_url',
                'tasks' => fn ($query) => $query->select(['id', 'thread_id', 'status'])->orderBy('id'),
                'children' => fn (Relation $relation) => self::scopeChildren($relation, $user, $status),
                'children.tasks' => fn ($query) => $query->select(['id', 'thread_id', 'status'])->orderBy('id'),
            ])
            ->orderByRaw('last_activity_at is null')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();

        $all = $roots->concat($roots->flatMap(fn (Thread $root) => $root->children));
        $runs = TaskRun::query()
            ->whereIn('id', $all->map(fn (Thread $thread): mixed => $thread->getAttribute('latest_run_id'))->filter()->all())
            ->get(['id', 'thread_id', 'turn_state', 'finished_at', 'updated_at'])
            ->keyBy('id');
        $seen = ThreadView::query()
            ->where('user_id', $user->id)
            ->whereIn('thread_id', $all->pluck('id')->all())
            ->pluck('last_seen_at', 'thread_id');

        $starredIds = ThreadStar::query()
            ->where('user_id', $user->id)
            ->whereIn('thread_id', $roots->pluck('id')->all())
            ->pluck('thread_id')
            ->all();

        $startOfDay = now()->startOfDay();
        $startOfWeek = now()->startOfWeek();
        $rows = [];

        foreach ($roots as $root) {
            $isPrivate = $root->visibility === ThreadVisibility::Private;
            $isShared = $isPrivate && (bool) $root->getAttribute('is_shared');
            $children = array_values($root->children->map(fn (Thread $child): array => self::row($child, $runs, $seen, $user, $startOfDay, $startOfWeek, $isPrivate, $isShared, [], false, false))->all());
            $row = self::row($root, $runs, $seen, $user, $startOfDay, $startOfWeek, $isPrivate, $isShared, $children, in_array($root->id, $starredIds, true), ! $isPrivate || $root->owner_user_id === $user->id);

            $needyChildren = array_values(array_filter($children, fn (array $child): bool => $child['needsYouReason'] !== null));

            if ($row['needsYouReason'] === null && $needyChildren !== []) {
                $count = count($needyChildren);
                $row['needsYouReason'] = $needyChildren[0]['needsYouReason'];
                $row['needsYouLabel'] = $count . ($count === 1 ? ' sub-thread needs you' : ' sub-threads need you');
            }

            $row['isUnread'] = $row['isUnread'] || in_array(true, array_column($children, 'isUnread'), true);
            $rows[] = $row;
        }

        $needsYouCount = count(array_filter($rows, fn (array $row): bool => $row['needsYouReason'] !== null));
        $facets = self::withPickedKeys(self::facets($rows, $user), $sources, $starters);

        // ponytail: filters the whole tab in memory; move to whereSources / whereStarters in SQL once the list paginates.
        $rows = array_filter($rows, fn (array $row): bool => ($sources === [] || in_array($row['source']['key'], $sources, true))
            && ($starters === [] || in_array($row['starter']['key'], $starters, true)));

        $starred = [];
        $needsYou = [];
        $rest = [];

        foreach ($rows as $row) {
            if ($row['isStarred']) {
                $starred[] = $row;
            } elseif ($row['needsYouReason'] === null) {
                $rest[] = $row;
            } else {
                $needsYou[] = $row;
            }
        }

        return [
            'starred' => $starred,
            'needsYou' => $needsYou,
            'threads' => $rest,
            'needsYouCount' => $needsYouCount,
            'hasAnyThreads' => $roots->isNotEmpty() || self::hasAnyThreads($user),
            'facets' => $facets,
        ];
    }

    /**
     * Counts the tab's root rows by source and by starter for the filter menu.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>}
     */
    private static function facets(array $rows, User $viewer): array
    {
        $sources = [];
        $starters = [];

        foreach ($rows as $row) {
            $source = $row['source'];
            $starter = $row['starter'];
            $sources[$source['key']] ??= ['key' => $source['key'], 'label' => $source['label'], 'count' => 0];
            $sources[$source['key']]['count']++;
            $starters[$starter['key']] ??= ['key' => $starter['key'], 'name' => $starter['name'], 'avatarUrl' => $starter['avatarUrl'], 'count' => 0];
            $starters[$starter['key']]['count']++;
        }

        $viewerKey = 'user:' . $viewer->id;
        $sourceList = array_values($sources);
        $starterList = array_values($starters);
        usort($sourceList, fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
        usort($starterList, fn (array $a, array $b): int => [$a['key'] === 'yak', $a['key'] !== $viewerKey, $b['count'], $a['name']]
            <=> [$b['key'] === 'yak', $b['key'] !== $viewerKey, $a['count'], $b['name']]);

        return ['sources' => $sourceList, 'starters' => $starterList];
    }

    /**
     * Appends each picked source and starter the tab has no rows for, with count 0, so the menu and chips can name it.
     *
     * @param  array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>}  $facets
     * @param  list<string>  $sources
     * @param  list<string>  $starters
     * @return array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>}
     */
    private static function withPickedKeys(array $facets, array $sources, array $starters): array
    {
        foreach (array_diff($sources, array_column($facets['sources'], 'key')) as $key) {
            $source = ThreadSource::tryFrom($key);

            if ($source !== null) {
                $facets['sources'][] = ['key' => $key, 'label' => $source->label(), 'count' => 0];
            }
        }

        $missing = array_unique(array_diff($starters, array_column($facets['starters'], 'key')));
        $userIds = [];

        foreach ($missing as $key) {
            if (preg_match('/^user:(\d+)$/', $key, $matches) === 1) {
                $userIds[] = (int) $matches[1];
            }
        }

        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'name', 'avatar_url'])->keyBy('id');

        foreach ($missing as $key) {
            $starter = match (true) {
                $key === 'yak' => ['name' => 'Yak', 'avatarUrl' => null],
                str_starts_with($key, 'name:') && strlen($key) > 5 => ['name' => substr($key, 5), 'avatarUrl' => null],
                preg_match('/^user:(\d+)$/', $key, $matches) === 1 && $users->has((int) $matches[1]) => [
                    'name' => $users->get((int) $matches[1])->name,
                    'avatarUrl' => is_string($avatarUrl = $users->get((int) $matches[1])->getAttribute('avatar_url')) ? $avatarUrl : null,
                ],
                default => null,
            };

            if ($starter !== null) {
                $facets['starters'][] = ['key' => $key, ...$starter, 'count' => 0];
            }
        }

        return $facets;
    }

    /**
     * Keeps a root whose title, or the title of a sub-thread the viewer may see, contains the search.
     *
     * @param  Builder<Thread>  $query
     */
    private static function matching(Builder $query, User $user, string $status, string $search): void
    {
        $pattern = self::likePattern($search);
        $like = 'lower(threads.title) like ? escape \'' . self::LIKE_ESCAPE . '\'';

        $query->where(function (Builder $query) use ($user, $status, $pattern, $like): void {
            $query->whereRaw($like, [$pattern])
                ->orWhereIn('threads.id', app(ThreadAccess::class)
                    ->scopeVisibleTo(Thread::query()->withoutGlobalScope(VisibleThreadScope::class), $user)
                    ->whereNotNull('threads.parent_thread_id')
                    ->where('threads.status', $status)
                    ->whereRaw($like, [$pattern])
                    ->select('threads.parent_thread_id'));
        });
    }

    private static function hasAnyThreads(User $user): bool
    {
        return Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($user)
            ->listed()
            ->whereNull('parent_thread_id')
            ->exists();
    }

    /**
     * @param  Relation<Thread, Thread, mixed>  $relation
     */
    private static function scopeChildren(Relation $relation, User $user, string $status): void
    {
        $children = $relation->getQuery()->withoutGlobalScope(VisibleThreadScope::class);

        app(ThreadAccess::class)->scopeVisibleTo($children, $user)
            ->where('status', $status)
            ->select('threads.*')
            ->addSelect(['latest_run_id' => self::latestRunId()])
            ->orderBy('id');
    }

    /**
     * @return Builder<TaskRun>
     */
    private static function latestRunId(): Builder
    {
        return TaskRun::query()
            ->select('id')
            ->whereColumn('task_runs.thread_id', 'threads.id')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * @param  Builder<Thread>  $query
     */
    private static function mine(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where('created_by_user_id', $user->id)
                ->orWhereHas('runs', fn (Builder $runs) => $runs->where('driver_user_id', $user->id))
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->where('started_by_user_id', $user->id));
        });
    }

    /**
     * A contains pattern for `lower(column) like ? escape '!'`: the search is lowercased and `%`, `_` and `!` match literally.
     */
    public static function likePattern(string $search): string
    {
        $escape = self::LIKE_ESCAPE;

        return '%' . preg_replace('/[' . preg_quote($escape, '/') . '%_]/', $escape . '$0', Str::lower($search)) . '%';
    }

    /**
     * @param  Collection<int, TaskRun>  $runs
     * @param  Collection<int, mixed>  $seen
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private static function row(Thread $thread, Collection $runs, Collection $seen, User $viewer, CarbonInterface $startOfDay, CarbonInterface $startOfWeek, bool $isPrivate, bool $isShared, array $children, bool $isStarred, bool $canDelete): array
    {
        $run = $runs->get($thread->getAttribute('latest_run_id'));
        [$reason, $label] = $thread->isOpen() ? self::needsYou($thread) : [null, null];
        $isUnread = $thread->isOpen() && self::isUnread($run, $seen->get($thread->id), $thread->created_by_user_id === $viewer->id);

        return [
            'id' => $thread->public_id,
            'title' => Str::limit($thread->title, 120),
            'repo' => $thread->repo,
            'step' => $thread->current_step,
            'status' => self::status($thread, $run)->value,
            'needsYouReason' => $reason?->value,
            'needsYouLabel' => $label,
            'isPrivate' => $isPrivate,
            'isShared' => $isShared,
            'isUnattended' => $thread->created_by_user_id === null,
            'isStarred' => $isStarred,
            'canDelete' => $canDelete,
            'isUnread' => $isUnread,
            'ownerInitials' => $thread->created_by_user_id !== null && $thread->created_by_user_id !== $viewer->id
                ? $thread->creator?->initials()
                : null,
            ...ThreadOriginData::for($thread),
            'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
            // A thread nobody has acted on yet is as old as its creation.
            'group' => match (true) {
                ($thread->last_activity_at ?? $thread->created_at) === null => 'older',
                ($thread->last_activity_at ?? $thread->created_at)->greaterThanOrEqualTo($startOfDay) => 'today',
                ($thread->last_activity_at ?? $thread->created_at)->greaterThanOrEqualTo($startOfWeek) => 'this_week',
                default => 'older',
            },
            'children' => $children,
        ];
    }

    private static function status(Thread $thread, ?TaskRun $run): ThreadListStatus
    {
        return match (true) {
            ! $thread->isOpen() => ThreadListStatus::Closed,
            in_array($run?->getAttribute('turn_state'), [TurnState::Running, TurnState::WaitingForAccount, TurnState::WaitingForSandbox], true) => ThreadListStatus::Running,
            $thread->blocked_reason === ThreadBlockReason::WaitingForRepository => ThreadListStatus::WaitingForRepository,
            $thread->blocked_reason !== null => ThreadListStatus::Blocked,
            $thread->sandbox_state === ThreadSandboxState::Paused => ThreadListStatus::Paused,
            default => ThreadListStatus::Stopped,
        };
    }

    /**
     * @return array{0: ?NeedsYouReason, 1: ?string}
     */
    private static function needsYou(Thread $thread): array
    {
        if ($thread->tasks->last()?->status === TaskStatus::AwaitingClarification) {
            return [NeedsYouReason::Question, 'Asked you a question'];
        }

        if ($thread->blocked_reason === ThreadBlockReason::WaitingForRepository) {
            return [null, null];
        }

        if ($thread->blocked_reason === ThreadBlockReason::RepositorySetupFailed) {
            return [NeedsYouReason::Blocked, 'Repository setup failed'];
        }

        if ($thread->blocked_reason !== null) {
            $isTokenProblem = in_array($thread->blocked_reason, [ThreadBlockReason::AccountInvalid, ThreadBlockReason::AccountExpired], true);

            return [NeedsYouReason::Blocked, $isTokenProblem ? 'Your token stopped working' : 'Blocked'];
        }

        return [null, null];
    }

    /**
     * Whether the latest turn finished after the viewer last opened the thread. A thread the viewer never opened
     * is unread only when the viewer started it, so a teammate's finished thread does not light up the Everyone tab.
     */
    private static function isUnread(?TaskRun $run, mixed $lastSeenAt, bool $isStartedByViewer): bool
    {
        if ($run === null || ! in_array($run->getAttribute('turn_state'), [TurnState::Completed, TurnState::Failed, TurnState::StoppedByGuard], true)) {
            return false;
        }

        $finishedAt = $run->finished_at ?? $run->updated_at;

        return $lastSeenAt === null ? $isStartedByViewer : ($finishedAt !== null && $finishedAt->greaterThan($lastSeenAt));
    }
}
