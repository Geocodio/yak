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
use App\Models\YakTask;
use App\Services\Threads\ThreadTasks;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What each run of a thread reached: a push, CI, the pull request, a walkthrough or a research report.
 * A row sits after the answer of its run, `afterSequence` being the last thread event of that run.
 * A thread made from an old task has no runs, so its rows hang on the task and sit at sequence 0.
 *
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
        $runs = TaskRun::query()->where('thread_id', $thread->id)->orderBy('id')->get();

        if ($runs->isEmpty()) {
            return [];
        }

        $endSequences = self::endSequences($thread, $runs);
        $tasks = $thread->tasks()->get()->keyBy('id');
        $found = [];

        foreach ($runs->groupBy('yak_task_id') as $taskId => $taskRuns) {
            $task = $tasks[$taskId] ?? null;

            if ($task !== null) {
                $found = [...$found, ...self::facts($task, $taskRuns->values())];
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
        $root = ThreadTasks::request($thread);
        $milestones = [];

        foreach ($root?->conversation() ?? [] as $task) {
            foreach (self::facts($task, collect()) as [$ownerKey, $milestone]) {
                $milestones[] = [...$milestone, 'runId' => $ownerKey, 'afterSequence' => 0];
            }
        }

        return $milestones;
    }

    /**
     * The last event sequence of each run that has events: the one before the next run's first event, else the newest.
     *
     * @param  Collection<int, TaskRun>  $runs
     * @return array<int, int>
     */
    public static function endSequences(Thread $thread, Collection $runs): array
    {
        $startSequences = [];

        foreach (ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'turn')->orderBy('sequence')->get(['sequence', 'payload']) as $event) {
            if (($event->payload['state'] ?? null) === 'running' && is_int($event->payload['turnId'] ?? null)) {
                $startSequences[$event->payload['turnId']] ??= $event->sequence;
            }
        }

        $newest = (int) ThreadEvent::query()->where('thread_id', $thread->id)->max('sequence');
        $ends = [];
        $following = null;

        foreach ($runs->reverse() as $run) {
            if (! isset($startSequences[$run->id])) {
                continue;
            }

            $ends[$run->id] = $following === null ? $newest : $following - 1;
            $following = $startSequences[$run->id];
        }

        return $ends;
    }

    /**
     * The milestones of one task, each with the key of the run (or, without runs, the task) that reached it.
     *
     * @param  Collection<int, TaskRun>  $runs  the task's runs, oldest first
     * @return list<array{0: string, 1: array{key: string, label: string, tone: string, url: ?string}}>
     */
    private static function facts(YakTask $task, Collection $runs): array
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

        foreach (TaskLog::query()->where('yak_task_id', $task->id)->where('message', ProcessCIResultJob::RESULT_LOG_MESSAGE)->orderBy('id')->get() as $log) {
            $ciByOwner[$ownerOf($log->created_at)] = ($log->metadata['passed'] ?? false) === true;
        }

        foreach ($ciByOwner as $ownerKey => $passed) {
            $found[] = [(string) $ownerKey, $passed
                ? ['key' => 'ci_passed', 'label' => 'CI passed', 'tone' => 'ok', 'url' => null]
                : ['key' => 'ci_failed', 'label' => 'CI failed', 'tone' => 'bad', 'url' => null]];
        }

        if ($task->pr_url !== null) {
            $found[] = [$ownerOf($task->pr_opened_at), ['key' => 'pull_request_opened', 'label' => $task->pr_number ? "Pull request #{$task->pr_number}" : 'Pull request', 'tone' => 'ok', 'url' => $task->pr_url]];
        } elseif (CoOwnerRequest::query()->where('task_id', $task->id)->where('status', CoOwnerRequestStatus::Pending)->exists()) {
            $found[] = [$ownerOf(null), ['key' => 'pull_request_held', 'label' => 'Pull request held for a co-owner', 'tone' => 'warn', 'url' => null]];
        }

        $cut = $task->artifacts()->cut()->latest('id')->first();

        if ($cut !== null) {
            $found[] = [$ownerOf($cut->created_at), ['key' => 'walkthrough_ready', 'label' => 'Walkthrough ready', 'tone' => 'ok', 'url' => null]];
        }

        $report = $task->mode === TaskMode::Research ? $task->artifacts()->where('type', 'research')->first() : null;

        if ($report instanceof Artifact) {
            $found[] = [$ownerOf(null), ['key' => 'research_report', 'label' => 'Research report', 'tone' => 'ok', 'url' => route('artifacts.viewer', ['task' => $task, 'filename' => $report->filename])]];
        }

        return $found;
    }
}
