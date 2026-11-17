<?php

namespace App\Services\Threads;

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Http\Resources\TaskRowData;
use App\Models\CoOwnerRequest;
use App\Models\Scopes\VisibleTaskScope;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tasks with no open thread that need a person: a question they were asked,
 * or a co-owner decision they owe. A task item is listed only for a task with
 * no open thread, because an open thread's row already says so.
 */
class NeedsYouTasks
{
    private const LIMIT = 50;

    /**
     * @return list<array{type: 'task', taskId: string, title: string, needsYouReason: 'question'|'co_owner', needsYouLabel: string, isPrivate: bool, lastActivityAt: string}>
     */
    public static function for(User $user, ?string $search = null): array
    {
        // Thread facts come from joins, so no relation scoped to the signed-in person is involved.
        $query = self::matching($user)
            ->leftJoin('threads as item_thread', 'item_thread.id', '=', 'tasks.thread_id')
            ->leftJoin('threads as item_root', 'item_root.id', '=', DB::raw('coalesce(item_thread.root_thread_id, item_thread.id)'))
            ->select('tasks.*', 'item_thread.status as item_thread_status', 'item_root.visibility as item_root_visibility')
            ->orderByDesc('tasks.updated_at')
            ->orderByDesc('tasks.id');

        // The title is the plain preview of the description, so a search filters after the query.
        if ($search === null || $search === '') {
            $query->limit(self::LIMIT);
        }

        $items = [];

        foreach ($query->get() as $task) {
            $isQuestion = $task->status === TaskStatus::AwaitingClarification
                && $task->started_by_user_id === $user->id
                && ($task->thread_id === null || $task->getAttribute('item_thread_status') === ThreadStatus::Closed->value);

            $items[] = [
                'type' => 'task',
                'taskId' => $task->public_id,
                'title' => Str::limit(TaskRowData::plainPreview((string) $task->description), 120),
                'needsYouReason' => $isQuestion ? 'question' : 'co_owner',
                'needsYouLabel' => $isQuestion ? 'Asked you a question' : 'Waiting for your co-owner decision',
                'isPrivate' => $task->getAttribute('item_root_visibility') === ThreadVisibility::Private->value,
                'lastActivityAt' => $task->updated_at?->toIso8601String() ?? '',
            ];
        }

        if ($search !== null && $search !== '') {
            $needle = Str::lower($search);
            $items = array_values(array_filter($items, fn (array $item): bool => str_contains(Str::lower($item['title']), $needle)));
        }

        return array_slice($items, 0, self::LIMIT);
    }

    /**
     * One count query that ignores the list cap.
     */
    public static function count(User $user): int
    {
        return self::matching($user)->count();
    }

    /**
     * @return Builder<YakTask>
     */
    private static function matching(User $user): Builder
    {
        $coOwnerTaskIds = CoOwnerRequest::query()
            ->select('task_id')
            ->where('status', CoOwnerRequestStatus::Pending)
            ->whereJsonContains('asked_user_ids', $user->id);

        $query = YakTask::withoutGlobalScopes();
        VisibleTaskScope::constrain($query, 'tasks', $user->id);

        return $query->where(function (Builder $query) use ($user, $coOwnerTaskIds): void {
            $query->where(fn (Builder $coOwner) => $coOwner->whereIn('tasks.id', $coOwnerTaskIds)
                ->where(fn (Builder $open) => self::withoutOpenThread($open)))
                ->orWhere(fn (Builder $question) => self::question($question, $user));
        });
    }

    /**
     * @param  Builder<YakTask>  $query
     */
    private static function question(Builder $query, User $user): void
    {
        $query->where('tasks.status', TaskStatus::AwaitingClarification)
            ->where('tasks.started_by_user_id', $user->id)
            ->where(fn (Builder $open) => self::withoutOpenThread($open));
    }

    /**
     * @param  Builder<YakTask>  $query
     */
    private static function withoutOpenThread(Builder $query): void
    {
        $query->whereNull('tasks.thread_id')
            ->orWhereHas('thread', fn (Builder $thread) => $thread->withoutGlobalScopes()->where('status', ThreadStatus::Closed));
    }
}
