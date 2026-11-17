<?php

namespace App\Services\Threads;

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Models\CoOwnerRequest;
use App\Models\TaskRun;
use App\Models\Thread;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One status for the whole request of a thread. The first match wins:
 * closed, waiting for a co-owner, needs you, stopped, working, done.
 * `for()` and `scope()` give the same answer.
 */
final class ThreadRequestStatus
{
    private const array KEYS = ['closed', 'waiting_for_co_owner', 'needs_you', 'stopped', 'working', 'done'];

    private const array STOPPED_TASK_STATUSES = [TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled];

    private const array WORKING_TASK_STATUSES = [TaskStatus::Pending, TaskStatus::Running, TaskStatus::Retrying, TaskStatus::AwaitingCi];

    /**
     * @return array{key: string, label: string, tone: string}
     */
    public static function for(Thread $thread): array
    {
        $key = match (true) {
            ! $thread->isOpen() => 'closed',
            CoOwnerRequest::query()->where('status', CoOwnerRequestStatus::Pending)->whereIn('task_id', $thread->tasks()->select('tasks.id'))->exists() => 'waiting_for_co_owner',
            default => self::keyForOpenThread($thread),
        };

        return self::describe($key);
    }

    /**
     * Narrows the threads to those whose status is one of the keys. Unknown
     * keys are ignored; with no known key the query is left alone.
     *
     * @param  Builder<Thread>  $threads
     * @param  list<string>  $keys
     * @return Builder<Thread>
     */
    public static function scope(Builder $threads, array $keys): Builder
    {
        $keys = array_values(array_intersect($keys, self::KEYS));

        if ($keys === []) {
            return $threads;
        }

        $predicates = self::predicates();

        return $threads->where(function (Builder $matching) use ($keys, $predicates): void {
            foreach (self::KEYS as $index => $key) {
                if (! in_array($key, $keys, true)) {
                    continue;
                }

                $matching->orWhere(function (Builder $group) use ($key, $index, $predicates): void {
                    foreach (array_slice(self::KEYS, 0, $index) as $earlier) {
                        $group->whereNot(fn (Builder $negated) => $predicates[$earlier]($negated));
                    }

                    if ($key !== 'done') {
                        $group->where(fn (Builder $own) => $predicates[$key]($own));
                    }
                });
            }
        });
    }

    private static function keyForOpenThread(Thread $thread): string
    {
        $head = ThreadTasks::head($thread);
        $latestRun = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();

        return match (true) {
            $head?->status === TaskStatus::AwaitingClarification, ! $thread->hasRepository() => 'needs_you',
            in_array($head?->status, self::STOPPED_TASK_STATUSES, true), $latestRun?->turn_state === TurnState::StoppedByGuard => 'stopped',
            in_array($head?->status, self::WORKING_TASK_STATUSES, true), ThreadTurns::activeTurn($thread) !== null => 'working',
            default => 'done',
        };
    }

    /**
     * Each key's own condition on the threads query.
     *
     * @return array<string, Closure(Builder<Thread>): mixed>
     */
    private static function predicates(): array
    {
        $headStatusIn = fn (array $statuses) => fn (Builder $threads) => $threads->whereExists(fn ($head) => $head->from('tasks as head')->selectRaw('1')
            ->whereColumn('head.thread_id', 'threads.id')
            ->whereIn('head.status', array_map(fn (TaskStatus $status): string => $status->value, $statuses))
            ->whereNotExists(fn ($later) => $later->from('tasks as later')->selectRaw('1')->whereColumn('later.thread_id', 'head.thread_id')->whereColumn('later.id', '>', 'head.id')));
        $hasTurnIn = fn (array $states, bool $latestOnly) => fn (Builder $threads) => $threads->whereExists(function ($run) use ($states, $latestOnly): void {
            $run->from('task_runs as run')->selectRaw('1')
                ->whereColumn('run.thread_id', 'threads.id')
                ->whereIn('run.turn_state', array_map(fn (TurnState $state): string => $state->value, $states));

            if ($latestOnly) {
                $run->whereNotExists(fn ($later) => $later->from('task_runs as later')->selectRaw('1')->whereColumn('later.thread_id', 'run.thread_id')->whereColumn('later.id', '>', 'run.id'));
            }
        });

        return [
            'closed' => fn (Builder $threads) => $threads->where('threads.status', ThreadStatus::Closed->value),
            'waiting_for_co_owner' => fn (Builder $threads) => $threads->whereExists(fn ($request) => $request->from('co_owner_requests')->selectRaw('1')
                ->where('co_owner_requests.status', CoOwnerRequestStatus::Pending->value)
                ->whereIn('co_owner_requests.task_id', fn ($tasks) => $tasks->from('tasks')->select('tasks.id')->whereColumn('tasks.thread_id', 'threads.id'))),
            'needs_you' => fn (Builder $threads) => $threads->where(fn (Builder $any) => $any
                ->where('threads.repo', Thread::UNKNOWN_REPOSITORY)
                ->orWhere($headStatusIn([TaskStatus::AwaitingClarification]))),
            'stopped' => fn (Builder $threads) => $threads->where(fn (Builder $any) => $any
                ->where($headStatusIn(self::STOPPED_TASK_STATUSES))
                ->orWhere($hasTurnIn([TurnState::StoppedByGuard], true))),
            'working' => fn (Builder $threads) => $threads->where(fn (Builder $any) => $any
                ->where($headStatusIn(self::WORKING_TASK_STATUSES))
                ->orWhere($hasTurnIn([...ThreadTurns::WAITING_STATES, TurnState::Running], false))),
        ];
    }

    /**
     * @return array{key: string, label: string, tone: string}
     */
    private static function describe(string $key): array
    {
        return match ($key) {
            'closed' => ['key' => 'closed', 'label' => 'Closed', 'tone' => 'neutral'],
            'waiting_for_co_owner' => ['key' => 'waiting_for_co_owner', 'label' => 'Waiting for a co-owner', 'tone' => 'warn'],
            'needs_you' => ['key' => 'needs_you', 'label' => 'Needs you', 'tone' => 'warn'],
            'stopped' => ['key' => 'stopped', 'label' => 'Stopped', 'tone' => 'bad'],
            'working' => ['key' => 'working', 'label' => 'Working', 'tone' => 'info'],
            default => ['key' => 'done', 'label' => 'Done', 'tone' => 'ok'],
        };
    }
}
