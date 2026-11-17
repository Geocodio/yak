<?php

namespace App\Support;

use App\Enums\TaskMode;
use App\Enums\TurnMessageKind;
use App\Http\Resources\RepositoryRunData;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;

/**
 * The one place that knows where a task lives now: a work task on its thread,
 * a review on the page of its pull request, a setup or repository research
 * run on the repository's Runs tab. Old links to `/tasks/{id}` and every
 * message that points at a task go through it.
 */
final class TaskPageUrl
{
    private const array ANCHORS = ['questions' => 'card-question', 'co-owner' => 'card-co-owner'];

    /**
     * The page of the task, or null when it has none: a work task whose thread is gone, a review with no pull
     * request, a setup or research run whose repository is gone. Query keys are the old names (`t`, `repo`,
     * `log`); a key the target cannot use is dropped.
     *
     * @param  array<string, mixed>  $query
     * @param  array{threads?: array<int, Thread>, repositories?: array<string, Repository>, anchors?: array<int, ?string>}  $lookups  rows already loaded for a list of tasks
     */
    public static function for(YakTask $task, ?string $anchor = null, array $query = [], bool $absolute = true, array $lookups = []): ?string
    {
        $target = self::target($task, $lookups);

        if ($target === null) {
            return null;
        }

        if ($target['kind'] === 'review') {
            return route('pr-reviews.for-pr', [$target['repo'], $target['number']], $absolute) . '#run-' . $task->public_id;
        }

        if ($target['kind'] === 'run') {
            return route('repos.runs.show', [$target['repository'], $task], $absolute);
        }

        $thread = $target['thread'];
        $parameters = [];

        $walkthrough = $query['walkthrough'] ?? $query['t'] ?? null;

        if (is_numeric($walkthrough)) {
            $parameters['walkthrough'] = (int) $walkthrough;
        }

        if (is_string($query['repo'] ?? null) && $query['repo'] !== '') {
            $parameters['repo'] = $query['repo'];
        }

        if ($thread->is_legacy && filled($query['log'] ?? null)) {
            $parameters['log'] = $query['log'];
        }

        $fragment = $anchor !== null
            ? (self::ANCHORS[$anchor] ?? $anchor)
            : (! $thread->is_legacy && $task->parent_task_id !== null
                ? (array_key_exists($task->id, $lookups['anchors'] ?? []) ? $lookups['anchors'][$task->id] : self::messageAnchor($task))
                : null);

        return route('threads.show', ['thread' => $thread->public_id, ...$parameters], $absolute) . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * The page of the task, else the thread log, for a redirect or a link that must point somewhere.
     */
    public static function forOrLog(YakTask $task, ?string $anchor = null): string
    {
        return self::for($task, $anchor) ?? route('threads.log');
    }

    /**
     * The page of the task with this primary key, whoever may see the task. For server-side messages only,
     * never for a response to a viewer.
     *
     * @param  array<string, mixed>  $query
     */
    public static function forTaskId(?int $taskId, ?string $anchor = null, array $query = [], bool $absolute = true): ?string
    {
        $task = $taskId === null ? null : YakTask::query()->withoutGlobalScopes()->find($taskId);

        return $task === null ? null : self::for($task, $anchor, $query, $absolute);
    }

    /**
     * The pages of several tasks as the signed-in viewer may see them, in a fixed number of queries
     * for work tasks. A task the viewer cannot see, or that has no page, maps to null.
     *
     * @param  list<int>  $taskIds
     * @return array<int, ?string>
     */
    public static function forVisibleTaskIds(array $taskIds): array
    {
        $tasks = YakTask::query()->whereIn('id', array_values(array_unique($taskIds)))->get();
        $threadIds = $tasks->pluck('thread_id')->filter()->unique()->all();
        $anchoredIds = $tasks->whereNotNull('parent_task_id')->pluck('id')->all();
        $anchors = [];

        foreach ($anchoredIds as $id) {
            $anchors[$id] = null;
        }

        ThreadTurnMessage::query()
            ->select(['thread_turn_messages.uuid', 'task_runs.yak_task_id'])
            ->join('task_runs', 'task_runs.id', '=', 'thread_turn_messages.task_run_id')
            ->where('thread_turn_messages.kind', TurnMessageKind::Prompt)
            ->whereIn('task_runs.yak_task_id', $anchoredIds)
            ->orderByDesc('thread_turn_messages.id')
            ->get()
            ->each(function (ThreadTurnMessage $message) use (&$anchors): void {
                $anchors[(int) $message->getAttribute('yak_task_id')] = self::messageId($message->uuid);
            });

        $lookups = [
            'threads' => Thread::query()->withoutGlobalScopes()->whereIn('id', $threadIds)->get()->keyBy('id')->all(),
            'repositories' => Repository::query()->whereIn('slug', $tasks->pluck('repo')->filter()->unique()->all())->get()->keyBy('slug')->all(),
            'anchors' => $anchors,
        ];

        $urls = array_fill_keys($taskIds, null);

        foreach ($tasks as $task) {
            $urls[$task->id] = self::for($task, lookups: $lookups);
        }

        return $urls;
    }

    /**
     * The noun for message texts: "thread", "review" or "run".
     */
    public static function label(YakTask $task): string
    {
        return match (self::target($task)['kind'] ?? 'thread') {
            'review' => 'review',
            'run' => 'run',
            default => 'thread',
        };
    }

    /**
     * The text of the button that opens the task's page: "Open thread", "Open review" or "Open run".
     */
    public static function buttonText(YakTask $task): string
    {
        return 'Open ' . self::label($task);
    }

    /**
     * The element id of a message on the thread page.
     */
    public static function messageId(string $uuid): string
    {
        return 'message-' . $uuid;
    }

    /**
     * The element id of the first message that a follow-up task carries into its thread.
     */
    public static function messageAnchor(YakTask $task): ?string
    {
        $uuid = ThreadTurnMessage::query()
            ->where('kind', TurnMessageKind::Prompt)
            ->whereHas('taskRun', fn ($runs) => $runs->where('yak_task_id', $task->id))
            ->orderBy('id')
            ->value('uuid');

        return is_string($uuid) ? self::messageId($uuid) : null;
    }

    /**
     * @param  array{threads?: array<int, Thread>, repositories?: array<string, Repository>, anchors?: array<int, ?string>}  $lookups
     * @return array{kind: 'review', repo: string, number: int}|array{kind: 'run', repository: Repository}|array{kind: 'thread', thread: Thread}|null
     */
    private static function target(YakTask $task, array $lookups = []): ?array
    {
        if ($task->mode === TaskMode::Review) {
            $number = $task->pullRequestNumber();

            return $task->repo === null || $number === null ? null : ['kind' => 'review', 'repo' => $task->repo, 'number' => $number];
        }

        if (RepositoryRunData::isRepositoryRun($task)) {
            $repository = $task->repo === null ? null : ($lookups['repositories'][$task->repo] ?? (isset($lookups['repositories']) ? null : Repository::query()->where('slug', $task->repo)->first()));

            return $repository === null ? null : ['kind' => 'run', 'repository' => $repository];
        }

        $thread = $task->thread_id === null ? null : ($lookups['threads'][$task->thread_id] ?? Thread::query()->withoutGlobalScopes()->find($task->thread_id));
        $thread ??= Thread::query()->withoutGlobalScopes()->where('is_legacy', true)->where('legacy_root_task_id', $task->rootTask()->id)->first();

        return $thread === null ? null : ['kind' => 'thread', 'thread' => $thread];
    }
}
