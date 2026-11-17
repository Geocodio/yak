<?php

namespace App\Http\Resources;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSource;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadOrigin;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The setup runs and repository research runs of one repository, for the Runs tab. A run is a task of this
 * repository with mode setup, or a research task that is the repository research (its thread has the source
 * `repository_research`, or its external id carries the risk profile marker). Tasks of private threads of
 * someone else are hidden by the task visibility scope, so such a run is neither listed nor opened.
 *
 * @phpstan-type Row array{id: string, kind: 'setup'|'repository_research', status: string, resultLine: string, startedBy: string, costUsd: float, startedAt: string, url: string, isLive: bool}
 * @phpstan-type Remembered array{testCommand: ?string, previewPort: ?int, startCommand: ?string, snapshot: ?string}
 * @phpstan-type Detail array{id: string, kind: 'setup'|'repository_research', status: string, isLive: bool, startedAt: string, startedBy: string, model: ?string, stepCount: int, durationSeconds: int, costUsd: float, resultLine: string, answerHtml: ?string, remembered: ?Remembered, waitingThreads: list<array{id: string, title: string, url: string}>, error: ?string, errorIsTruncated: bool, errorDetails: ?string, canRetry: bool, logUrl: string, retryUrl: ?string, threadUrl: ?string, riskProfileUrl: ?string}
 */
final class RepositoryRunData
{
    private const int LIST_LIMIT = 100;

    private const int WAITING_THREAD_LIMIT = 20;

    private const array STATUS_FILTERS = [
        'queued' => [TaskStatus::Pending],
        'running' => [TaskStatus::Running, TaskStatus::Retrying, TaskStatus::AwaitingCi, TaskStatus::AwaitingClarification, TaskStatus::AwaitingCoOwner],
        'finished' => [TaskStatus::Success],
        'failed' => [TaskStatus::Failed, TaskStatus::Expired],
        'cancelled' => [TaskStatus::Cancelled],
    ];

    /**
     * @return Builder<YakTask>
     */
    public static function query(Repository $repository): Builder
    {
        return YakTask::query()
            ->where('repo', $repository->slug)
            ->where(fn (Builder $query) => $query
                ->where('mode', TaskMode::Setup)
                ->orWhere(fn (Builder $research) => $research
                    ->where('mode', TaskMode::Research)
                    ->where(fn (Builder $marker) => $marker
                        ->where('external_id', 'like', 'risk-profile-%')
                        ->orWhereIn('thread_id', Thread::query()->withoutGlobalScopes()->where('source', ThreadSource::RepositoryResearch->value)->select('id')))));
    }

    public static function belongsToRepository(YakTask $task, Repository $repository): bool
    {
        if ($task->repo !== $repository->slug) {
            return false;
        }

        if ($task->mode === TaskMode::Setup) {
            return true;
        }

        return $task->mode === TaskMode::Research
            && (app(ThreadOrigin::class)->isRiskProfileResearch($task) || self::threadSource($task) === ThreadSource::RepositoryResearch);
    }

    /**
     * @param  array{kind: ?string, status: ?string}  $filters
     * @return array{rows: list<Row>, totalCount: int}
     */
    public static function list(Repository $repository, array $filters): array
    {
        $query = self::query($repository);

        if (in_array($filters['kind'] ?? null, ['setup', 'repository_research'], true)) {
            $query->where('mode', $filters['kind'] === 'setup' ? TaskMode::Setup : TaskMode::Research);
        }

        if (isset(self::STATUS_FILTERS[$filters['status'] ?? ''])) {
            $query->whereIn('status', self::STATUS_FILTERS[$filters['status']]);
        }

        $totalCount = (clone $query)->count();
        $tasks = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
        $names = self::userNames($tasks);

        return [
            'rows' => $tasks->map(fn (YakTask $task): array => self::row($task, $repository, $names, $filters))->values()->all(),
            'totalCount' => $totalCount,
        ];
    }

    /**
     * @param  Collection<int, YakTask>  $tasks
     * @return Collection<int, string>
     */
    private static function userNames(Collection $tasks): Collection
    {
        return User::query()->whereIn('id', $tasks->pluck('started_by_user_id')->filter()->unique())->pluck('name', 'id');
    }

    /**
     * @param  Collection<int, string>  $names
     * @param  array{kind: ?string, status: ?string}  $filters
     * @return Row
     */
    private static function row(YakTask $task, Repository $repository, Collection $names, array $filters): array
    {
        $status = self::status($task);

        return [
            'id' => $task->public_id,
            'kind' => self::kind($task),
            'status' => $status,
            'resultLine' => self::resultLine($task, $status),
            'startedBy' => self::startedBy($task, $names),
            'costUsd' => (float) $task->cost_usd,
            'startedAt' => ($task->started_at ?? $task->created_at)->toIso8601String(),
            'url' => route('repos.runs.show', ['repository' => $repository, 'task' => $task, 'kind' => $filters['kind'] ?? null, 'status' => $filters['status'] ?? null]),
            'isLive' => in_array($status, ['queued', 'running'], true),
        ];
    }

    /**
     * @return Detail
     */
    public static function detail(YakTask $run, Repository $repository): array
    {
        $kind = self::kind($run);
        $status = self::status($run);
        /** @var TaskStatus $taskStatus */
        $taskStatus = $run->status;
        $isSetup = $kind === 'setup';
        $thread = $isSetup || $run->thread_id === null ? null : Thread::query()->find($run->thread_id);
        $summary = trim((string) $run->result_summary);

        return [
            'id' => $run->public_id,
            'kind' => $kind,
            'status' => $status,
            'isLive' => in_array($status, ['queued', 'running'], true),
            'startedAt' => ($run->started_at ?? $run->created_at)->toIso8601String(),
            'startedBy' => self::startedBy($run, self::userNames(new Collection([$run]))),
            'model' => $run->resolvedModel(),
            'stepCount' => (int) $run->num_turns,
            'durationSeconds' => intdiv((int) $run->duration_ms, 1000),
            'costUsd' => (float) $run->cost_usd,
            'resultLine' => self::resultLine($run, $status),
            'answerHtml' => $summary === '' ? null : Markdown::toHtml($summary),
            'remembered' => $isSetup && $taskStatus === TaskStatus::Success && self::newestSetupId($repository, TaskStatus::Success) === $run->id ? self::remembered($repository) : null,
            'waitingThreads' => $isSetup && self::newestSetupId($repository) === $run->id ? self::waitingThreads($repository) : [],
            ...PrReviewRunData::error($run, $taskStatus, 'The run stopped without a recorded reason.'),
            'canRetry' => $isSetup && in_array($taskStatus, [TaskStatus::Failed, TaskStatus::Expired], true),
            'logUrl' => route('repos.runs.log', ['repository' => $repository, 'task' => $run]),
            'retryUrl' => $isSetup ? route('repos.runs.retry', ['repository' => $repository, 'task' => $run]) : null,
            'threadUrl' => $thread === null ? null : route('threads.show', $thread),
            'riskProfileUrl' => $isSetup ? null : route('repos.risk-profile.show', $repository),
        ];
    }

    public static function kind(YakTask $task): string
    {
        return $task->mode === TaskMode::Setup ? 'setup' : 'repository_research';
    }

    /**
     * `queued`, `running`, `ready` (setup) or `done` (research), `failed` and `cancelled`.
     */
    public static function status(YakTask $task): string
    {
        return match ($task->status) {
            TaskStatus::Pending => 'queued',
            TaskStatus::Success => $task->mode === TaskMode::Setup ? 'ready' : 'done',
            TaskStatus::Failed, TaskStatus::Expired => 'failed',
            TaskStatus::Cancelled => 'cancelled',
            default => 'running',
        };
    }

    private static function resultLine(YakTask $task, string $status): string
    {
        if ($status === 'failed') {
            $firstLine = trim((string) strtok(trim((string) $task->error_log), "\n"));

            return 'Failed: ' . Str::limit($firstLine === '' ? 'no recorded reason' : $firstLine, 80);
        }

        if ($status === 'ready') {
            $parts = array_filter(['Ready', $task->num_turns > 0 ? "{$task->num_turns} steps" : null, $task->duration_ms > 0 ? self::duration(intdiv((int) $task->duration_ms, 1000)) : null]);

            return implode(', ', $parts);
        }

        return match ($status) {
            'queued' => 'Waiting for a free sandbox',
            'running' => 'Working',
            'cancelled' => 'Cancelled',
            default => 'Done',
        };
    }

    private static function duration(int $seconds): string
    {
        return $seconds < 60 ? "{$seconds}s" : round($seconds / 60) . 'm';
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private static function startedBy(YakTask $task, Collection $names): string
    {
        if ($task->started_by_user_id !== null && $names->has($task->started_by_user_id)) {
            return $names->get($task->started_by_user_id);
        }

        if ($task->source !== 'system' || preg_match('/\(([^()]+)\)$/', (string) $task->description, $match) !== 1) {
            return 'Yak';
        }

        return match ($match[1]) {
            'yak-base updated' => 'Yak, after a base image update',
            '.yak/setup.sh changed' => 'Yak, after the setup script changed',
            'first task for this repository' => 'Yak, for the first task',
            default => 'Yak',
        };
    }

    private static function threadSource(YakTask $task): ?ThreadSource
    {
        return $task->thread_id === null ? null : Thread::query()->withoutGlobalScopes()->whereKey($task->thread_id)->first()?->source;
    }

    private static function newestSetupId(Repository $repository, ?TaskStatus $status = null): ?int
    {
        return self::query($repository)
            ->where('mode', TaskMode::Setup)
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * What setup stored for the repository. A row without a stored source is left out.
     *
     * @return Remembered|null
     */
    private static function remembered(Repository $repository): ?array
    {
        $manifest = $repository->preview_manifest ?? [];
        $remembered = [
            'testCommand' => filled($repository->test_command) ? (string) $repository->test_command : null,
            'previewPort' => isset($manifest['port']) ? (int) $manifest['port'] : null,
            'startCommand' => filled($manifest['cold_start'] ?? null) ? (string) $manifest['cold_start'] : null,
            'snapshot' => filled($repository->sandbox_snapshot)
                ? 'Saved' . ($repository->sandbox_base_version === null ? '' : ", sandbox base version {$repository->sandbox_base_version}")
                : null,
        ];

        return count(array_filter($remembered, fn (mixed $value): bool => $value !== null)) === 0 ? null : $remembered;
    }

    /**
     * The threads of this repository that wait for it right now and that the viewer may see.
     *
     * @return list<array{id: string, title: string, url: string}>
     */
    private static function waitingThreads(Repository $repository): array
    {
        return Thread::query()
            ->where('repo', $repository->slug)
            ->whereIn('blocked_reason', [ThreadBlockReason::WaitingForRepository, ThreadBlockReason::RepositorySetupFailed])
            ->orderBy('blocked_at')
            ->limit(self::WAITING_THREAD_LIMIT)
            ->get()
            ->map(fn (Thread $thread): array => ['id' => $thread->public_id, 'title' => $thread->title, 'url' => route('threads.show', $thread)])
            ->all();
    }
}
