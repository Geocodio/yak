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
     */
    public static function for(YakTask $task, ?string $anchor = null, array $query = [], bool $absolute = true): ?string
    {
        $target = self::target($task);

        if ($target === null) {
            return null;
        }

        if ($target['kind'] === 'review') {
            return $target['externalUrl'] ?? route('pr-reviews.for-pr', [$target['repo'], $target['number']], $absolute) . '#run-' . $task->public_id;
        }

        if ($target['kind'] === 'run') {
            return route('repos.runs.show', [$target['repository'], $task], $absolute);
        }

        $thread = $target['thread'];
        $parameters = [];

        if (isset($query['t']) || isset($query['walkthrough'])) {
            $parameters['walkthrough'] = (int) ($query['walkthrough'] ?? $query['t']);
        }

        if (filled($query['repo'] ?? null)) {
            $parameters['repo'] = $query['repo'];
        }

        if ($thread->is_legacy && filled($query['log'] ?? null)) {
            $parameters['log'] = $query['log'];
        }

        $fragment = $anchor !== null
            ? (self::ANCHORS[$anchor] ?? $anchor)
            : (! $thread->is_legacy && $task->parent_task_id !== null ? self::messageAnchor($task) : null);

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
     * The page of the task with this primary key, whoever may see the task.
     *
     * @param  array<string, mixed>  $query
     */
    public static function forTaskId(?int $taskId, ?string $anchor = null, array $query = [], bool $absolute = true): ?string
    {
        $task = $taskId === null ? null : YakTask::query()->withoutGlobalScopes()->find($taskId);

        return $task === null ? null : self::for($task, $anchor, $query, $absolute);
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
     * @return array{kind: 'review', repo: string, number: int, externalUrl: ?string}|array{kind: 'run', repository: Repository}|array{kind: 'thread', thread: Thread}|null
     */
    private static function target(YakTask $task): ?array
    {
        if ($task->mode === TaskMode::Review) {
            $number = $task->pullRequestNumber();

            if ($task->repo === null || ($number === null && blank($task->pr_url))) {
                return null;
            }

            return ['kind' => 'review', 'repo' => $task->repo, 'number' => (int) $number, 'externalUrl' => $number === null ? $task->pr_url : null];
        }

        if (RepositoryRunData::isRepositoryRun($task)) {
            $repository = $task->repo === null ? null : Repository::query()->where('slug', $task->repo)->first();

            return $repository === null ? null : ['kind' => 'run', 'repository' => $repository];
        }

        $thread = $task->thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($task->thread_id);
        $thread ??= Thread::query()->withoutGlobalScopes()->where('is_legacy', true)->where('legacy_root_task_id', $task->rootTask()->id)->first();

        return $thread === null ? null : ['kind' => 'thread', 'thread' => $thread];
    }
}
