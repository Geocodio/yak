<?php

namespace App\Services\Tasks;

use App\DataTransferObjects\TaskListFilters;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\DeploymentStatus;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Resources\TaskRowData;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;
use App\Support\Tasks\ArtifactPreviewUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The task list for the web and the app: rows, counts and filter options.
 */
class TaskListQuery
{
    public function __construct(private readonly int $perPage = 50) {}

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    public function rows(TaskListFilters $filters, int $page): LengthAwarePaginator
    {
        $status = $filters->status ?? '';
        $search = $filters->search !== null && trim($filters->search) !== '' ? ThreadList::likePattern(trim($filters->search)) : null;

        /** @var LengthAwarePaginator<int, YakTask> $tasks */
        $tasks = $this->scopedQuery($filters->tab)
            ->with('repository')
            ->whereNull('parent_task_id')
            ->when($status === TaskStatus::AwaitingCoOwner->value, fn (Builder $query) => $query->whereIn('id', $this->heldRootIds()))
            ->when($status !== '' && $status !== TaskStatus::AwaitingCoOwner->value, fn (Builder $query) => $query->where('status', $status))
            ->when($filters->tab === 'tasks' && $filters->source !== null, fn (Builder $query) => $query->where('source', $filters->source))
            ->when($filters->repo !== null, fn (Builder $query) => $query->where('repo', $filters->repo))
            ->when($filters->pr !== null, fn (Builder $query) => $this->applyPrFilter($query, (string) $filters->pr))
            ->when($search !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereRaw("lower(description) like ? escape '!'", [$search])
                ->orWhereRaw("lower(external_id) like ? escape '!'", [$search])))
            ->orderBy($filters->sort, $filters->direction)
            ->orderByDesc('id')
            ->paginate($this->perPage, ['*'], 'page', $page);

        $descendantsByBranch = $this->descendantsByBranch($tasks->items());
        $previewsByTask = $this->previewsByTask($tasks->items());
        $deploymentsByTask = $this->deploymentsByTask($tasks->items());
        $askedFirstNames = $this->askedFirstNames($tasks->items());

        return $tasks->through(fn (YakTask $task): array => TaskRowData::from($task, [
            'askedFirstName' => $askedFirstNames[$task->id] ?? null,
            'children' => $task->branch_name !== null
                ? ($descendantsByBranch->get($task->branch_name) ?? collect())
                : collect(),
            'preview' => $previewsByTask[$task->id] ?? null,
            'deployment' => $task->branch_name !== null
                ? $deploymentsByTask->get($task->repo . '/' . $task->branch_name)
                : null,
        ]));
    }

    /**
     * @return array{tasks: int, reviews: int, setup: int, needsCoOwner: int}
     */
    public function counts(): array
    {
        return [
            'tasks' => $this->scopedQuery('tasks')->whereNull('parent_task_id')->count(),
            'reviews' => $this->scopedQuery('reviews')->whereNull('parent_task_id')->count(),
            'setup' => $this->scopedQuery('setup')->whereNull('parent_task_id')->count(),
            'needsCoOwner' => $this->scopedQuery('tasks')->whereNull('parent_task_id')->whereIn('id', $this->heldRootIds())->count(),
        ];
    }

    /**
     * @return array{repos: list<string>, sources: list<string>}
     */
    public function options(): array
    {
        return ['repos' => $this->distinctRepos(), 'sources' => $this->distinctSources()];
    }

    /**
     * Root task ids with a pending co-owner request. The request can sit on
     * a follow-up child, so the root's own status cannot find it.
     *
     * @return Builder<CoOwnerRequest>
     */
    private function heldRootIds(): Builder
    {
        return CoOwnerRequest::query()
            ->where('status', CoOwnerRequestStatus::Pending)
            ->select('root_task_id');
    }

    /**
     * First name of the person asked first, keyed by root task id.
     *
     * @param  iterable<int, YakTask>  $tasks
     * @return array<int, string>
     */
    private function askedFirstNames(iterable $tasks): array
    {
        $requests = CoOwnerRequest::query()
            ->where('status', CoOwnerRequestStatus::Pending)
            ->whereIn('root_task_id', collect($tasks)->pluck('id'))
            ->orderBy('id')
            ->get();

        $users = User::query()->whereIn('id', $requests->map(fn (CoOwnerRequest $request) => $request->asked_user_ids[0] ?? null)->filter())->get()->keyBy('id');

        $names = [];

        foreach ($requests as $request) {
            $user = $users->get($request->asked_user_ids[0] ?? 0);

            if ($user !== null) {
                $names[$request->root_task_id] = Str::before($user->name, ' ');
            }
        }

        return $names;
    }

    /**
     * @param  Builder<YakTask>  $query
     * @return Builder<YakTask>
     */
    private function applyPrFilter(Builder $query, string $state): Builder
    {
        return match ($state) {
            'open' => $query->whereNotNull('pr_url')->whereNull('pr_merged_at')->whereNull('pr_closed_at'),
            'merged' => $query->whereNotNull('pr_merged_at'),
            'closed' => $query->whereNotNull('pr_closed_at')->whereNull('pr_merged_at'),
            'none' => $query->whereNull('pr_url'),
            default => $query,
        };
    }

    /**
     * @return Builder<YakTask>
     */
    private function scopedQuery(string $tab): Builder
    {
        return match ($tab) {
            'setup' => YakTask::query()->where('mode', TaskMode::Setup),
            'reviews' => YakTask::query()->where('mode', TaskMode::Review),
            default => YakTask::query()->whereIn('mode', [TaskMode::Fix, TaskMode::Research]),
        };
    }

    /**
     * Follow-up descendants for the given roots, grouped by branch_name, so
     * a whole follow-up chain (children, grandchildren, ...) is captured in
     * a single query.
     *
     * @param  iterable<int, YakTask>  $tasks
     * @return Collection<string, EloquentCollection<int, YakTask>>
     */
    private function descendantsByBranch(iterable $tasks): Collection
    {
        $branches = collect($tasks)->pluck('branch_name')->filter()->unique()->values();

        if ($branches->isEmpty()) {
            return collect();
        }

        return YakTask::query()
            ->whereNotNull('parent_task_id')
            ->whereIn('branch_name', $branches)
            ->orderBy('created_at')
            ->get()
            ->groupBy('branch_name');
    }

    /**
     * Poster and preview-GIF URLs for the given tasks, in one query.
     *
     * @param  iterable<int, YakTask>  $tasks
     * @return array<int, array{poster: ?string, gif: ?string}>
     */
    private function previewsByTask(iterable $tasks): array
    {
        $taskIds = collect($tasks)->pluck('id')->all();

        if ($taskIds === []) {
            return [];
        }

        $previews = [];

        $artifacts = Artifact::query()
            ->whereIn('yak_task_id', $taskIds)
            ->whereIn('role', ['preview', 'thumbnail'])
            ->orderBy('id')
            ->get();

        foreach ($artifacts as $artifact) {
            $url = ArtifactPreviewUrl::for($artifact);

            if ($artifact->role === 'preview') {
                $previews[$artifact->yak_task_id]['gif'] = $url;
            } else {
                $previews[$artifact->yak_task_id]['poster'] = $url;
            }
        }

        return collect($previews)
            ->map(fn (array $preview): array => [
                'poster' => $preview['poster'] ?? $preview['gif'] ?? null,
                'gif' => $preview['gif'] ?? null,
            ])
            ->all();
    }

    /**
     * Live branch deployments for the given roots, keyed by
     * "repo slug/branch name".
     *
     * @param  iterable<int, YakTask>  $tasks
     * @return Collection<string, BranchDeployment>
     */
    private function deploymentsByTask(iterable $tasks): Collection
    {
        $withBranch = collect($tasks)->filter(fn (YakTask $task) => $task->branch_name !== null);

        if ($withBranch->isEmpty()) {
            return collect();
        }

        return BranchDeployment::query()
            ->with('repository')
            ->whereIn('branch_name', $withBranch->pluck('branch_name')->unique()->values())
            ->whereNotIn('status', [DeploymentStatus::Destroyed, DeploymentStatus::Destroying])
            ->get()
            ->keyBy(fn (BranchDeployment $deployment): string => $deployment->repository->slug . '/' . $deployment->branch_name);
    }

    /**
     * @return list<string>
     */
    private function distinctRepos(): array
    {
        return array_values(YakTask::query()
            ->whereNotNull('repo')
            ->distinct()
            ->pluck('repo')
            ->map(fn (mixed $value): string => (string) $value)
            ->sort()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function distinctSources(): array
    {
        return array_values(YakTask::query()
            ->whereNotNull('source')
            ->distinct()
            ->pluck('source')
            ->map(fn (mixed $value): string => (string) $value)
            ->sort()
            ->all());
    }
}
