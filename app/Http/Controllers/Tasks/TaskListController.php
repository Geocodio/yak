<?php

namespace App\Http\Controllers\Tasks;

use App\Channels\ChannelRegistry;
use App\Enums\DeploymentStatus;
use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskRowData;
use App\Livewire\Tasks\Support\ArtifactPreviewUrl;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Support\Docs;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TaskListController extends Controller
{
    /** @var list<string> */
    private const array SORTABLE_COLUMNS = ['status', 'source', 'author_name', 'repo', 'created_at'];

    public function __invoke(Request $request): Response
    {
        $tab = $request->string('tab', 'tasks')->toString();
        $status = $request->string('status')->toString();
        $source = $request->string('source')->toString();
        $repo = $request->string('repo')->toString();
        $pr = $request->string('pr')->toString();
        $sort = $request->string('sort', 'created_at')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';

        return Inertia::render('Tasks/Index', [
            'tasks' => fn () => $this->paginatedTasks($tab, $status, $source, $repo, $pr, $sort, $direction),
            'counts' => fn () => [
                'tasks' => $this->scopedQuery('tasks')->whereNull('parent_task_id')->count(),
                'reviews' => $this->scopedQuery('reviews')->whereNull('parent_task_id')->count(),
                'setup' => $this->scopedQuery('setup')->whereNull('parent_task_id')->count(),
            ],
            'filters' => [
                'status' => $status,
                'source' => $source,
                'repo' => $repo,
                'pr' => $pr,
                'sort' => $sort,
                'direction' => $direction,
                'tab' => $tab,
                'options' => fn () => [
                    'repos' => $this->distinctRepos(),
                    'sources' => $this->distinctSources(),
                ],
            ],
            'setupCard' => fn () => $this->setupCard($request->user()),
            'activeRepos' => fn () => Repository::query()
                ->where('is_active', true)
                ->orderBy('slug')
                ->pluck('slug')
                ->all(),
            'openNew' => $request->boolean('new'),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedTasks(
        string $tab,
        string $status,
        string $source,
        string $repo,
        string $pr,
        string $sort,
        string $direction,
    ): LengthAwarePaginator {
        $sortColumn = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'created_at';

        /** @var LengthAwarePaginator<int, YakTask> $tasks */
        $tasks = $this->scopedQuery($tab)
            ->with('repository')
            ->whereNull('parent_task_id')
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($tab === 'tasks' && $source !== '', fn (Builder $query) => $query->where('source', $source))
            ->when($repo !== '', fn (Builder $query) => $query->where('repo', $repo))
            ->when($pr !== '', fn (Builder $query) => $this->applyPrFilter($query, $pr))
            ->orderBy($sortColumn, $direction)
            ->orderByDesc('id')
            ->paginate(50);

        $descendantsByBranch = $this->descendantsByBranch($tasks->items());
        $previewsByTask = $this->previewsByTask($tasks->items());
        $deploymentsByTask = $this->deploymentsByTask($tasks->items());

        return $tasks->through(fn (YakTask $task): array => TaskRowData::from($task, [
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
     * @return array<int, string>
     */
    private function distinctRepos(): array
    {
        return YakTask::query()
            ->whereNotNull('repo')
            ->distinct()
            ->pluck('repo')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function distinctSources(): array
    {
        return YakTask::query()
            ->whereNotNull('source')
            ->distinct()
            ->pluck('source')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The "Getting started" card shown on a bare install until the user
     * dismisses it or the install matures (a repo and a task both exist).
     *
     * @return array{items: list<array{title: string, body: string, done: bool, url: string, external: bool}>}|null
     */
    private function setupCard(?User $user): ?array
    {
        if ($user === null || $user->has_seen_setup_card_at !== null) {
            return null;
        }

        if (Repository::count() > 0 && YakTask::count() > 0) {
            return null;
        }

        $anyRepo = Repository::count() > 0;
        $registry = app(ChannelRegistry::class);
        $anyChannelEnabled = collect(['slack', 'linear', 'sentry', 'github', 'drone'])
            ->contains(fn (string $channel) => $registry->for($channel)?->enabled() ?? false);
        $anyTask = YakTask::count() > 0;

        return [
            'items' => [
                [
                    'title' => 'Add your first repository',
                    'body' => 'Yak clones it and dispatches a setup task automatically.',
                    'done' => $anyRepo,
                    'url' => route('repos.create'),
                    'external' => false,
                ],
                [
                    'title' => 'Connect a channel',
                    'body' => 'Slack, Linear, or Sentry — tasks come in from configured channels.',
                    'done' => $anyChannelEnabled,
                    'url' => Docs::url('channels'),
                    'external' => true,
                ],
                [
                    'title' => 'Send your first task',
                    'body' => 'Mention @yak in Slack or assign a Linear issue to Yak.',
                    'done' => $anyTask,
                    'url' => Docs::url('channels.slack'),
                    'external' => true,
                ],
            ],
        ];
    }
}
