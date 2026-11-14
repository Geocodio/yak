<?php

namespace App\Services\Threads;

use App\Enums\NeedsYouReason;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadListFilter;
use App\Enums\ThreadListStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadVisibility;
use App\Enums\TurnState;
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
 * isUnattended: bool, isStarred: bool, canDelete: bool, ownerInitials: ?string, lastActivityAt: ?string, group: 'today'|'this_week'|'older',
 * children: list<array<string, mixed>>}`.
 */
class ThreadList
{
    private const LIKE_ESCAPE = '!';

    /**
     * @return array{starred: list<array<string, mixed>>, needsYou: list<array<string, mixed>>, threads: list<array<string, mixed>>, needsYouCount: int, hasAnyThreads: bool}
     */
    public static function for(User $user, ThreadListFilter $filter = ThreadListFilter::Mine, ?string $search = null): array
    {
        $status = $filter === ThreadListFilter::Closed ? 'closed' : 'open';
        $roots = Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($user)
            ->whereNull('parent_thread_id')
            ->where('status', $status)
            ->when($filter === ThreadListFilter::Mine, fn (Builder $query) => self::mine($query, $user))
            ->when($filter === ThreadListFilter::StartedByYak, fn (Builder $query) => $query->whereNull('created_by_user_id'))
            ->when($search !== null && $search !== '', fn (Builder $query) => self::search($query, (string) $search))
            ->select('threads.*')
            ->addSelect(['latest_run_id' => self::latestRunId()])
            ->withExists('shares as is_shared')
            ->with([
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

        $owners = User::query()
            ->whereIn('id', $all->pluck('created_by_user_id')->filter()->unique()->all())
            ->get(['id', 'name'])
            ->keyBy('id');

        $startOfDay = now()->startOfDay();
        $startOfWeek = now()->startOfWeek();
        $starred = [];
        $needsYou = [];
        $rest = [];

        foreach ($roots as $root) {
            $isPrivate = $root->visibility === ThreadVisibility::Private;
            $isShared = $isPrivate && (bool) $root->getAttribute('is_shared');
            $children = array_values($root->children->map(fn (Thread $child): array => self::row($child, $runs, $seen, $owners, $user, $startOfDay, $startOfWeek, $isPrivate, $isShared, [], false, false))->all());
            $row = self::row($root, $runs, $seen, $owners, $user, $startOfDay, $startOfWeek, $isPrivate, $isShared, $children, in_array($root->id, $starredIds, true), ! $isPrivate || $root->owner_user_id === $user->id);

            $needyChildren = array_values(array_filter($children, fn (array $child): bool => $child['needsYouReason'] !== null));

            if ($row['needsYouReason'] === null && $needyChildren !== []) {
                $count = count($needyChildren);
                $row['needsYouReason'] = $needyChildren[0]['needsYouReason'];
                $row['needsYouLabel'] = $count . ($count === 1 ? ' sub-thread needs you' : ' sub-threads need you');
            }

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
            'needsYouCount' => count($needsYou) + count(array_filter($starred, fn (array $row): bool => $row['needsYouReason'] !== null)),
            'hasAnyThreads' => $roots->isNotEmpty() || self::hasAnyThreads($user),
        ];
    }

    private static function hasAnyThreads(User $user): bool
    {
        return Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($user)
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
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->where('started_by_user_id', $user->id))
                ->orWhere(function (Builder $unattended) use ($user): void {
                    $unattended->whereNull('created_by_user_id')
                        ->whereIn('repo', Thread::query()->withoutVisibilityScope()->where('created_by_user_id', $user->id)->select('repo'));
                });
        });
    }

    /**
     * @param  Builder<Thread>  $query
     */
    private static function search(Builder $query, string $search): void
    {
        $escape = self::LIKE_ESCAPE;
        $pattern = '%' . preg_replace('/[' . preg_quote($escape, '/') . '%_]/', $escape . '$0', Str::lower($search)) . '%';

        $query->where(function (Builder $query) use ($pattern, $escape): void {
            $query->whereRaw("lower(threads.title) like ? escape '{$escape}'", [$pattern])
                ->orWhereRaw("lower(threads.repo) like ? escape '{$escape}'", [$pattern]);
        });
    }

    /**
     * @param  Collection<int, TaskRun>  $runs
     * @param  Collection<int, mixed>  $seen
     * @param  Collection<int, User>  $owners
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private static function row(Thread $thread, Collection $runs, Collection $seen, Collection $owners, User $viewer, CarbonInterface $startOfDay, CarbonInterface $startOfWeek, bool $isPrivate, bool $isShared, array $children, bool $isStarred, bool $canDelete): array
    {
        $run = $runs->get($thread->getAttribute('latest_run_id'));
        [$reason, $label] = $thread->isOpen() ? self::needsYou($thread, $run, $seen->get($thread->id)) : [null, null];

        return [
            'id' => $thread->public_id,
            'title' => Str::limit($thread->title, 120),
            'repo' => $thread->repo,
            'status' => self::status($thread, $run)->value,
            'needsYouReason' => $reason?->value,
            'needsYouLabel' => $label,
            'isPrivate' => $isPrivate,
            'isShared' => $isShared,
            'isUnattended' => $thread->created_by_user_id === null,
            'isStarred' => $isStarred,
            'canDelete' => $canDelete,
            'ownerInitials' => $thread->created_by_user_id !== null && $thread->created_by_user_id !== $viewer->id
                ? $owners->get($thread->created_by_user_id)?->initials()
                : null,
            'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
            'group' => match (true) {
                $thread->last_activity_at === null => 'older',
                $thread->last_activity_at->greaterThanOrEqualTo($startOfDay) => 'today',
                $thread->last_activity_at->greaterThanOrEqualTo($startOfWeek) => 'this_week',
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
    private static function needsYou(Thread $thread, ?TaskRun $run, mixed $lastSeenAt): array
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

        $isFinished = $run !== null && in_array($run->getAttribute('turn_state'), [TurnState::Completed, TurnState::Failed, TurnState::StoppedByGuard], true);

        if ($isFinished) {
            $finishedAt = $run->finished_at ?? $run->updated_at;

            if ($lastSeenAt === null || ($finishedAt !== null && $finishedAt->greaterThan($lastSeenAt))) {
                return [NeedsYouReason::Unseen, 'Finished, not seen yet'];
            }
        }

        return [null, null];
    }
}
