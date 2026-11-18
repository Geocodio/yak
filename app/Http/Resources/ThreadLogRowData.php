<?php

namespace App\Http\Resources;

use App\Models\Thread;
use App\Services\Threads\ThreadRequestStatus;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One row of the thread log. The pull request comes with the page query ({@see ThreadLogQuery::for}); the status, cost, follow-ups and
 * the person asked are read for the whole page in a fixed number of queries.
 *
 * @phpstan-type LogRow array{
 *     id: string,
 *     url: string,
 *     title: string,
 *     statusLine: string,
 *     status: array{key: string, label: string, tone: string},
 *     source: array{key: string, label: string, reference: ?string, url: ?string},
 *     starter: array{key: string, name: string, avatarUrl: ?string},
 *     repo: ?string,
 *     pullRequest: ?array{number: ?int, url: ?string, state: string},
 *     costUsd: float,
 *     lastActivityAt: string,
 *     followUpCount: int,
 * }
 */
final class ThreadLogRowData
{
    private const array STATUS_KEYS_BY_PRIORITY = ['closed', 'waiting_for_co_owner', 'needs_you', 'stopped', 'working'];

    /**
     * @param  Collection<int, Thread>  $threads
     * @return list<LogRow>
     */
    public static function forPage(Collection $threads): array
    {
        if ($threads->isEmpty()) {
            return [];
        }

        $ids = array_values($threads->map(fn (Thread $thread): int => $thread->id)->all());
        (new EloquentCollection($threads->all()))->loadMissing('creator:id,name,avatar_url');

        $statusKeys = [];
        foreach (self::STATUS_KEYS_BY_PRIORITY as $key) {
            $matching = ThreadRequestStatus::scope(Thread::query()->withoutVisibilityScope()->whereIn('threads.id', $ids), [$key])->pluck('threads.id');
            foreach ($matching as $id) {
                $statusKeys[(int) $id] ??= $key;
            }
        }

        $tasks = DB::table('tasks')->whereIn('thread_id', $ids)->orderBy('id')->get(['id', 'thread_id', 'status', 'parent_task_id'])->groupBy('thread_id');
        $firstAskedByThread = self::firstAskedNames($ids);
        $costs = DB::table('task_runs')->whereIn('thread_id', $ids)->groupBy('thread_id')->selectRaw('thread_id, sum(cost_usd) as total')->pluck('total', 'thread_id');

        return array_values($threads->map(function (Thread $thread) use ($statusKeys, $tasks, $firstAskedByThread, $costs): array {
            $status = ThreadRequestStatus::describe($statusKeys[$thread->id] ?? 'done');
            $threadTasks = $tasks->get($thread->id, collect());
            $followUpCount = $threadTasks->whereNotNull('parent_task_id')->count();
            $pullRequest = $thread->getAttribute('pull_request_url') === null ? null : [
                'number' => $thread->getAttribute('pull_request_number') !== null ? (int) $thread->getAttribute('pull_request_number') : null,
                'url' => (string) $thread->getAttribute('pull_request_url'),
                'state' => (string) $thread->getAttribute('pull_request_state'),
            ];

            if ($pullRequest === null && $status['key'] === 'waiting_for_co_owner') {
                $pullRequest = ['number' => null, 'url' => null, 'state' => 'held'];
            }

            return [
                'id' => $thread->public_id,
                'url' => route('threads.show', $thread),
                'title' => $thread->title,
                'statusLine' => self::statusLine($thread, $status['key'], $threadTasks->last()->status ?? null, $followUpCount, $pullRequest !== null, $firstAskedByThread[$thread->id] ?? null),
                'status' => $status,
                ...ThreadOriginData::for($thread),
                'repo' => $thread->hasRepository() ? $thread->repo : null,
                'pullRequest' => $pullRequest,
                'costUsd' => round((float) ($costs[$thread->id] ?? 0), 4),
                'lastActivityAt' => ($thread->last_activity_at ?? $thread->created_at ?? now())->toIso8601String(),
                'followUpCount' => $followUpCount,
            ];
        })->all());
    }

    /**
     * The status label with the most useful detail that is cheap to know.
     */
    public static function statusLine(Thread $thread, string $statusKey, ?string $headTaskStatus, int $followUpCount, bool $hasPullRequest, ?string $askedFirstName): string
    {
        return match ($statusKey) {
            'closed' => $hasPullRequest ? 'Closed' : 'Closed without a PR',
            'waiting_for_co_owner' => 'Waiting for a co-owner' . ($askedFirstName !== null ? ': ' . $askedFirstName : ''),
            'needs_you' => match (true) {
                $headTaskStatus === 'awaiting_clarification' => 'Asked a question',
                ! $thread->hasRepository() => 'Needs a repository',
                default => 'Needs you',
            },
            'stopped' => match ($headTaskStatus) {
                'failed' => 'Stopped, the run failed',
                'expired' => 'Stopped, it expired',
                'cancelled' => 'Stopped, cancelled',
                default => 'Stopped by a guard',
            },
            'working' => match ($headTaskStatus) {
                'awaiting_ci' => 'Working, waiting for CI',
                'retrying' => 'Working, retrying',
                default => 'Working',
            },
            default => 'Done' . ($followUpCount > 0 ? ', ' . $followUpCount . ($followUpCount === 1 ? ' follow-up' : ' follow-ups') : ''),
        };
    }

    /**
     * First name of the person asked first, per thread with a pending co-owner request.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function firstAskedNames(array $ids): array
    {
        $requests = DB::table('co_owner_requests')
            ->join('tasks', 'tasks.id', '=', 'co_owner_requests.task_id')
            ->whereIn('tasks.thread_id', $ids)
            ->where('co_owner_requests.status', 'pending')
            ->orderBy('co_owner_requests.id')
            ->get(['tasks.thread_id', 'co_owner_requests.asked_user_ids']);

        $askedByThread = [];
        foreach ($requests as $request) {
            $askedId = json_decode((string) $request->asked_user_ids, true)[0] ?? null;
            if ($askedId !== null) {
                $askedByThread[(int) $request->thread_id] ??= (int) $askedId;
            }
        }

        if ($askedByThread === []) {
            return [];
        }

        $names = DB::table('users')->whereIn('id', array_values($askedByThread))->pluck('name', 'id');
        $firstNames = [];
        foreach ($askedByThread as $threadId => $userId) {
            if (isset($names[$userId])) {
                $firstNames[$threadId] = Str::before((string) $names[$userId], ' ');
            }
        }

        return $firstNames;
    }
}
