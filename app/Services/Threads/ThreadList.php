<?php

namespace App\Services\Threads;

use App\Enums\TaskStatus;
use App\Enums\ThreadListStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The sidebar's thread list: open threads that need the viewer first, then the rest by last activity.
 */
class ThreadList
{
    /**
     * @return array{needsYou: list<array<string, mixed>>, threads: list<array<string, mixed>>}
     */
    public static function for(User $user, string $filter = 'mine', ?string $search = null, ?ThreadListStatus $status = null, int $limit = 50): array
    {
        $threads = Thread::query()
            ->where('status', 'open')
            ->when($filter !== 'everyone', fn (Builder $query) => self::mine($query, $user))
            ->with([
                'tasks' => fn ($query) => $query->select(['id', 'thread_id', 'parent_task_id', 'status', 'description', 'description_summary'])->orderBy('id'),
                'latestTurn',
                'claudeAccount',
                'views' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->orderByRaw('last_activity_at is null')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();

        $needsYou = [];
        $rest = [];

        foreach ($threads as $thread) {
            $root = $thread->tasks->first(fn (YakTask $task): bool => $task->parent_task_id === null) ?? $thread->tasks->first();
            $threadStatus = self::statusOf($thread);

            if ($status !== null && $threadStatus !== $status) {
                continue;
            }

            if ($search !== null && $search !== '' && ! self::matches($thread, $root, $search)) {
                continue;
            }

            $reason = self::needsYouReason($thread);
            $row = [
                'id' => $thread->id,
                'taskId' => $root?->id,
                'title' => Str::limit($root?->headline() ?? $thread->repo, 120),
                'repo' => $thread->repo,
                'status' => $threadStatus->value,
                'needsYouReason' => $reason,
                'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
                'accountName' => $thread->claudeAccount?->name,
                'accountIcon' => $thread->claudeAccount?->icon->value,
                'accountColor' => $thread->claudeAccount?->color->value,
            ];

            if ($reason === null) {
                $rest[] = $row;
            } else {
                $needsYou[] = $row;
            }
        }

        return ['needsYou' => $needsYou, 'threads' => array_slice($rest, 0, $limit)];
    }

    /**
     * @param  Builder<Thread>  $query
     */
    private static function mine(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user): void {
            $query->where('created_by_user_id', $user->id)
                ->orWhereHas('turns', fn (Builder $turns) => $turns->where('driver_user_id', $user->id))
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->where('started_by_user_id', $user->id))
                ->orWhere(function (Builder $unattended) use ($user): void {
                    $unattended->whereNull('created_by_user_id')
                        ->whereIn('repo', Thread::query()->where('created_by_user_id', $user->id)->select('repo'));
                });
        });
    }

    private static function statusOf(Thread $thread): ThreadListStatus
    {
        return match (true) {
            $thread->blocked_reason !== null => ThreadListStatus::Blocked,
            in_array($thread->latestTurn?->turn_state, [TurnState::Queued, TurnState::Running], true) => ThreadListStatus::Running,
            $thread->sandbox_state === ThreadSandboxState::Paused => ThreadListStatus::Paused,
            default => ThreadListStatus::Stopped,
        };
    }

    private static function needsYouReason(Thread $thread): ?string
    {
        if ($thread->tasks->last()?->status === TaskStatus::AwaitingClarification) {
            return 'question';
        }

        if ($thread->blocked_reason !== null) {
            return 'blocked';
        }

        $turn = $thread->latestTurn;
        $isFinished = $turn !== null && in_array($turn->turn_state, [TurnState::Completed, TurnState::Failed, TurnState::StoppedByGuard], true);

        if (! $isFinished) {
            return null;
        }

        $lastSeenAt = $thread->views->first()?->last_seen_at;
        $finishedAt = $turn->finished_at ?? $turn->updated_at;

        return $lastSeenAt === null || $finishedAt === null || $finishedAt->greaterThan($lastSeenAt) ? 'unseen' : null;
    }

    private static function matches(Thread $thread, ?YakTask $root, string $search): bool
    {
        return Str::contains(
            Str::lower(implode("\n", [$thread->repo, $root?->description, $root?->description_summary])),
            Str::lower($search),
        );
    }
}
