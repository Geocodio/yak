<?php

namespace App\Http\Resources;

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskMode;
use App\Jobs\ProcessCIResultJob;
use App\Models\Artifact;
use App\Models\CoOwnerRequest;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Threads\ThreadTasks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What each run of a thread reached: a push, CI, the pull request, a walkthrough or a research report.
 * A row sits after the answer of its run, `afterSequence` being the last thread event of that run.
 * A thread made from an old task has no runs, so its rows hang on the task and sit at sequence 0.
 *
 * @phpstan-type Loaded array{logs: Collection<int|string, EloquentCollection<int, TaskLog>>, held: array<int, true>, cut: Collection<int|string, Artifact>, reports: Collection<int|string, Artifact>}
 * @phpstan-type ThreadMilestone array{key: string, label: string, tone: string, url: ?string, runId: string, afterSequence: int}
 */
final class ThreadMilestonesData
{
    /**
     * @return list<ThreadMilestone>
     */
    public static function for(Thread $thread): array
    {
        return $thread->is_legacy ? self::forTasks($thread) : self::forRuns($thread);
    }

    /**
     * @return list<ThreadMilestone>
     */
    private static function forRuns(Thread $thread): array
    {
        ['runs' => $runs, 'ends' => $endSequences] = self::boundaries($thread);

        if ($runs->isEmpty()) {
            return [];
        }

        $tasks = $thread->tasks()->get()->keyBy('id');
        $loaded = self::loaded($tasks);
        $found = [];

        foreach ($runs->groupBy('yak_task_id') as $taskId => $taskRuns) {
            $task = $tasks[$taskId] ?? null;

            if ($task !== null) {
                $found = [...$found, ...self::facts($task, $taskRuns->values(), $loaded)];
            }
        }

        $milestones = [];

        foreach ($runs as $run) {
            $afterSequence = $endSequences[$run->id] ?? null;

            foreach ($found as [$ownerKey, $milestone]) {
                if ($afterSequence !== null && $ownerKey === (string) $run->id) {
                    $milestones[] = [...$milestone, 'runId' => $ownerKey, 'afterSequence' => $afterSequence];
                }
            }
        }

        return $milestones;
    }

    /**
     * @return list<ThreadMilestone>
     */
    private static function forTasks(Thread $thread): array
    {
        $tasks = (ThreadTasks::request($thread)?->conversation() ?? collect())->keyBy('id');
        $loaded = self::loaded($tasks);
        $milestones = [];

        foreach ($tasks as $task) {
            foreach (self::facts($task, collect(), $loaded) as [$ownerKey, $milestone]) {
                $milestones[] = [...$milestone, 'runId' => $ownerKey, 'afterSequence' => 0];
            }
        }

        return $milestones;
    }

    /**
     * The thread's runs (oldest first, only the columns the page needs) and the last event sequence of each run that
     * has events. A run starts at the first of its `turn running` event and the `started` state of its message, so the
     * person's message that starts the next run belongs after the previous run's last event. The result is kept on
     * the thread instance, so a page computes it once.
     *
     * @return array{runs: Collection<int, TaskRun>, ends: array<int, int>}
     */
    public static function boundaries(Thread $thread): array
    {
        if ($thread->relationLoaded('runBoundaries')) {
            /** @var array{runs: Collection<int, TaskRun>, ends: array<int, int>} */
            return $thread->getRelation('runBoundaries');
        }

        $runs = TaskRun::query()->where('thread_id', $thread->id)->orderBy('id')
            ->get(['id', 'yak_task_id', 'thread_id', 'kind', 'model', 'turn_state', 'started_at', 'commits', 'total_ms', 'num_turns']);
        $starts = [];

        if ($runs->isNotEmpty()) {
            $runIdByUuid = ThreadTurnMessage::query()->whereIn('task_run_id', $runs->pluck('id'))->whereNotNull('uuid')->pluck('task_run_id', 'uuid');
            $events = ThreadEvent::query()->where('thread_id', $thread->id)
                ->where(fn ($query) => $query->where(fn ($turn) => $turn->where('type', 'turn')->where('payload->state', 'running'))
                    ->orWhere(fn ($message) => $message->where('type', 'message.state')->where('payload->state', 'started')))
                ->orderBy('sequence')->get(['sequence', 'type', 'payload']);

            foreach ($events as $event) {
                $runId = $event->type === 'turn' ? ($event->payload['turnId'] ?? null) : ($runIdByUuid[$event->payload['uuid'] ?? ''] ?? null);

                if (is_int($runId)) {
                    $starts[$runId] ??= $event->sequence;
                }
            }
        }

        $newest = $starts === [] ? 0 : (int) ThreadEvent::query()->where('thread_id', $thread->id)->max('sequence');
        $ends = [];
        $following = null;

        foreach ($runs->reverse() as $run) {
            if (! isset($starts[$run->id])) {
                continue;
            }

            $ends[$run->id] = $following === null ? $newest : $following - 1;
            $following = $starts[$run->id];
        }

        $boundaries = ['runs' => $runs, 'ends' => $ends];
        $thread->setRelation('runBoundaries', $boundaries);

        return $boundaries;
    }

    /**
     * The CI results, pending co-owner requests and artifacts of all the tasks, in one query each.
     *
     * @param  Collection<int, YakTask>  $tasks
     * @return Loaded
     */
    private static function loaded(Collection $tasks): array
    {
        $ids = $tasks->pluck('id')->all();
        $artifacts = Artifact::query()->whereIn('yak_task_id', $ids)->where(fn ($query) => $query->where('role', 'cut')->orWhere('type', 'research'))->orderBy('id')->get();

        return [
            'logs' => TaskLog::query()->whereIn('yak_task_id', $ids)->where('message', ProcessCIResultJob::RESULT_LOG_MESSAGE)->orderBy('id')->get()->groupBy('yak_task_id'),
            'held' => array_fill_keys(CoOwnerRequest::query()->whereIn('task_id', $ids)->where('status', CoOwnerRequestStatus::Pending)->get()->map(fn (CoOwnerRequest $request): int => $request->task_id)->all(), true),
            'cut' => $artifacts->where('role', 'cut')->groupBy('yak_task_id')->map(fn (Collection $group) => $group->last())->filter(),
            'reports' => $artifacts->where('type', 'research')->groupBy('yak_task_id')->map(fn (Collection $group) => $group->first())->filter(),
        ];
    }

    /**
     * The milestones of one task, each with the key of the run (or, without runs, the task) that reached it.
     *
     * @param  Collection<int, TaskRun>  $runs  the task's runs, oldest first
     * @param  Loaded  $loaded
     * @return list<array{0: string, 1: array{key: string, label: string, tone: string, url: ?string}}>
     */
    private static function facts(YakTask $task, Collection $runs, array $loaded): array
    {
        $ownerOf = function (?CarbonInterface $at) use ($task, $runs): string {
            if ($runs->isEmpty()) {
                return $task->public_id;
            }

            $owner = $at === null ? null : $runs->last(fn (TaskRun $run): bool => $run->started_at <= $at);

            return (string) ($owner ?? $runs->last())->id;
        };
        $found = [];

        foreach ($runs->isEmpty() ? ($task->branch_name !== null ? [$task] : []) : $runs->filter(fn (TaskRun $run): bool => (int) $run->commits > 0) as $pusher) {
            $found[] = [$pusher instanceof TaskRun ? (string) $pusher->id : $task->public_id, ['key' => 'pushed', 'label' => $task->branch_name ? "Pushed {$task->branch_name}" : 'Pushed', 'tone' => 'ok', 'url' => null]];
        }

        $ciByOwner = [];

        foreach ($loaded['logs'][$task->id] ?? [] as $log) {
            $ciByOwner[$ownerOf($log->created_at)] = ($log->metadata['passed'] ?? false) === true;
        }

        foreach ($ciByOwner as $ownerKey => $passed) {
            $found[] = [(string) $ownerKey, $passed
                ? ['key' => 'ci_passed', 'label' => 'CI passed', 'tone' => 'ok', 'url' => null]
                : ['key' => 'ci_failed', 'label' => 'CI failed', 'tone' => 'bad', 'url' => null]];
        }

        if ($task->pr_url !== null) {
            $found[] = [$ownerOf($task->pr_opened_at), ['key' => 'pull_request_opened', 'label' => $task->pr_number ? "Pull request #{$task->pr_number}" : 'Pull request', 'tone' => 'ok', 'url' => $task->pr_url]];
        } elseif (isset($loaded['held'][$task->id])) {
            $found[] = [$ownerOf(null), ['key' => 'pull_request_held', 'label' => 'Pull request held for a co-owner', 'tone' => 'warn', 'url' => null]];
        }

        $cut = $loaded['cut'][$task->id] ?? null;

        if ($cut !== null) {
            $found[] = [$ownerOf($cut->created_at), ['key' => 'walkthrough_ready', 'label' => 'Walkthrough ready', 'tone' => 'neutral', 'url' => null]];
        }

        $report = $task->mode === TaskMode::Research ? ($loaded['reports'][$task->id] ?? null) : null;

        if ($report instanceof Artifact) {
            $found[] = [$ownerOf(null), ['key' => 'research_report', 'label' => 'Research report', 'tone' => 'ok', 'url' => route('artifacts.viewer', ['task' => $task, 'filename' => $report->filename])]];
        }

        return $found;
    }
}
