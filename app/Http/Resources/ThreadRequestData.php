<?php

namespace App\Http\Resources;

use App\Enums\TaskRunOutcome;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\TaskProgressSteps;
use App\Services\Threads\ThreadRequestStatus;
use App\Services\Threads\ThreadTasks;
use Carbon\CarbonImmutable;

/**
 * Where a thread's request came from, how far it got and what its runs cost.
 *
 * @phpstan-type ThreadRequestInfo array{
 *     source: array{key: string, label: string, reference: ?string, url: ?string},
 *     starter: array{key: string, name: string, avatarUrl: ?string},
 *     responsibleName: ?string,
 *     status: array{key: string, label: string, tone: string},
 * }
 * @phpstan-type ThreadProgress array{steps: list<array{key: string, label: string, tooltip: string, state: string}>}
 * @phpstan-type ThreadRunDetails array{runCount: int, stepCount: int, attempts: ?string, durationSeconds: int, costUsd: float, apiSpendUsd: float, sessionId: ?string, startedAt: ?string, completedAt: ?string, errorLog: ?string, errorLogTruncated: bool}
 */
final class ThreadRequestData
{
    private const int ERROR_LOG_LIMIT_BYTES = 16384;

    /** The fixed key of each step slot, in order. The co-owner slot exists only when the thread page shows it. */
    private const array STEP_KEYS = ['received', 'picked_up', 'working', 'pushed', 'ci', 'co_owner', 'pull_request', 'done'];

    /**
     * @return array{request: ThreadRequestInfo, progress: ?ThreadProgress, runDetails: ThreadRunDetails}
     */
    public static function for(Thread $thread, ?User $viewer): array
    {
        $head = ThreadTasks::head($thread);

        return [
            'request' => [
                ...ThreadOriginData::for($thread),
                'responsibleName' => ThreadTasks::request($thread)?->responsible_name,
                'status' => ThreadRequestStatus::for($thread),
            ],
            'progress' => self::progress($thread, $head),
            'runDetails' => self::runDetails($thread, $head),
        ];
    }

    /**
     * @return ?ThreadProgress
     */
    private static function progress(Thread $thread, ?YakTask $head): ?array
    {
        if ($head === null || ! $thread->isOpen() || $head->status === TaskStatus::Success) {
            return null;
        }

        $steps = TaskProgressSteps::for(ThreadTasks::pullRequestTask($thread) ?? $head, $head, ThreadTasks::coOwnerRequest($thread));

        $keys = count($steps) === count(self::STEP_KEYS) ? self::STEP_KEYS : array_values(array_diff(self::STEP_KEYS, ['co_owner']));

        return ['steps' => array_map(fn (array $step, int $index): array => [
            'key' => $keys[$index],
            'label' => $step['label'],
            'tooltip' => $step['tooltip'],
            'state' => match (true) {
                $step['failed'] => 'failed',
                $step['current'] => 'current',
                $step['done'] => 'done',
                default => 'upcoming',
            },
        ], $steps, array_keys($steps))];
    }

    /**
     * @return ThreadRunDetails
     */
    private static function runDetails(Thread $thread, ?YakTask $head): array
    {
        $totals = TaskRun::query()->where('thread_id', $thread->id)
            ->selectRaw('count(*) as run_count, coalesce(sum(num_turns), 0) as step_count, coalesce(sum(total_ms), 0) as total_ms, coalesce(sum(cost_usd), 0) as cost_usd, min(started_at) as started_at, max(finished_at) as finished_at, sum(case when finished_at is null then 1 else 0 end) as unfinished')
            ->toBase()->get()->sole();
        $latest = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();
        $hasFailed = $latest !== null && ($latest->turn_state === TurnState::Failed || in_array($latest->outcome, [TaskRunOutcome::Error, TaskRunOutcome::Exception], true));
        $errorLog = $hasFailed ? ($head->error_log ?? $latest->error_message) : null;
        $isFinished = (int) $totals->run_count > 0 && (int) $totals->unfinished === 0;

        return [
            'runCount' => (int) $totals->run_count,
            'stepCount' => (int) $totals->step_count,
            'attempts' => $head !== null && $head->attempts > 0 ? $head->attempts . ' of ' . (int) config('yak.max_attempts') : null,
            'durationSeconds' => intdiv((int) $totals->total_ms, 1000),
            'costUsd' => (float) $totals->cost_usd,
            'apiSpendUsd' => ThreadTasks::apiSpendUsd($thread->tasks()->select('tasks.id')),
            'sessionId' => $thread->activeSession()?->session_id,
            'startedAt' => $totals->started_at === null ? null : CarbonImmutable::parse($totals->started_at)->toIso8601String(),
            'completedAt' => $isFinished && $totals->finished_at !== null ? CarbonImmutable::parse($totals->finished_at)->toIso8601String() : null,
            'errorLog' => $errorLog === null ? null : mb_strcut($errorLog, 0, self::ERROR_LOG_LIMIT_BYTES),
            'errorLogTruncated' => $errorLog !== null && strlen($errorLog) > self::ERROR_LOG_LIMIT_BYTES,
        ];
    }
}
