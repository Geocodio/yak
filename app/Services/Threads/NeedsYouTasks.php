<?php

namespace App\Services\Threads;

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Enums\ThreadStatus;
use App\Http\Resources\TaskRowData;
use App\Models\CoOwnerRequest;
use App\Models\Scopes\VisibleTaskScope;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Tasks outside an open thread that need a person: a question they were asked,
 * or a co-owner decision they owe. A task inside an open thread is never listed
 * here because its thread row already says so.
 */
class NeedsYouTasks
{
    private const LIMIT = 50;

    /**
     * @return list<array{type: 'task', taskId: string, title: string, needsYouReason: 'question'|'co_owner', needsYouLabel: string, lastActivityAt: string}>
     */
    public static function for(User $user, ?string $search = null): array
    {
        $coOwnerTaskIds = CoOwnerRequest::query()
            ->where('status', CoOwnerRequestStatus::Pending)
            ->whereJsonContains('asked_user_ids', $user->id)
            ->pluck('task_id')
            ->all();

        $tasks = self::visible($user)
            ->where(function (Builder $query) use ($user, $coOwnerTaskIds): void {
                $query->whereIn('tasks.id', $coOwnerTaskIds)
                    ->orWhere(fn (Builder $question) => self::question($question, $user));
            })
            ->orderByDesc('tasks.updated_at')
            ->orderByDesc('tasks.id')
            ->get();

        $items = [];

        foreach ($tasks as $task) {
            $isQuestion = $task->status === TaskStatus::AwaitingClarification
                && $task->started_by_user_id === $user->id
                && ($task->thread_id === null || $task->thread?->status === ThreadStatus::Closed);

            $items[] = [
                'type' => 'task',
                'taskId' => $task->public_id,
                'title' => Str::limit(TaskRowData::plainPreview((string) $task->description), 120),
                'needsYouReason' => $isQuestion ? 'question' : 'co_owner',
                'needsYouLabel' => $isQuestion ? 'Asked you a question' : 'Waiting for your co-owner decision',
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
     * @return Builder<YakTask>
     */
    private static function visible(User $user): Builder
    {
        $query = YakTask::withoutGlobalScopes()->with('thread');
        VisibleTaskScope::constrain($query, 'tasks', $user->id);

        return $query;
    }

    /**
     * @param  Builder<YakTask>  $query
     */
    private static function question(Builder $query, User $user): void
    {
        $query->where('tasks.status', TaskStatus::AwaitingClarification)
            ->where('tasks.started_by_user_id', $user->id)
            ->where(fn (Builder $open) => $open->whereNull('tasks.thread_id')
                ->orWhereHas('thread', fn (Builder $thread) => $thread->withoutGlobalScopes()->where('status', ThreadStatus::Closed)));
    }
}
