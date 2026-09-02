<?php

namespace App\Livewire\Tasks;

use App\Channels\ChannelRegistry;
use App\Enums\DeploymentStatus;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\Support\ArtifactPreviewUrl;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Support\Docs;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read LengthAwarePaginator<int, YakTask> $tasks
 * @property-read Collection<string, BranchDeployment> $deploymentsByTask
 */
#[Title('Tasks')]
class TaskList extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'tasks';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $repo = '';

    /** PR lifecycle filter: '', 'open', 'merged', 'closed', or 'none'. */
    #[Url]
    public string $pr = '';

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    /** @var list<string> */
    public const SORTABLE_COLUMNS = ['status', 'source', 'author_name', 'repo', 'created_at'];

    /**
     * @return LengthAwarePaginator<int, YakTask>
     */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        return $this->scopedQuery($this->tab)
            ->with(['repository'])
            ->whereNull('parent_task_id')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->tab === 'tasks' && $this->source !== '', fn ($query) => $query->where('source', $this->source))
            ->when($this->repo !== '', fn ($query) => $query->where('repo', $this->repo))
            ->when($this->pr !== '', fn ($query) => $this->applyPrFilter($query, $this->pr))
            ->orderBy($this->sortColumn(), $this->sortDirection())
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * @param  Builder<YakTask>  $query
     * @return Builder<YakTask>
     */
    protected function applyPrFilter(Builder $query, string $state): Builder
    {
        return match ($state) {
            'open' => $query->whereNotNull('pr_url')->whereNull('pr_merged_at')->whereNull('pr_closed_at'),
            'merged' => $query->whereNotNull('pr_merged_at'),
            'closed' => $query->whereNotNull('pr_closed_at')->whereNull('pr_merged_at'),
            'none' => $query->whereNull('pr_url'),
            default => $query,
        };
    }

    protected function sortColumn(): string
    {
        return in_array($this->sort, self::SORTABLE_COLUMNS, true) ? $this->sort : 'created_at';
    }

    protected function sortDirection(): string
    {
        return $this->direction === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Clicking a column header sorts by it; clicking the active column
     * flips the direction. Created defaults to newest first, everything
     * else to ascending.
     */
    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = $column === 'created_at' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    /**
     * Live branch deployments for the roots on the current page, keyed by
     * "repo slug/branch name" so the table can show a preview link without
     * a per-row lookup.
     *
     * @return Collection<string, BranchDeployment>
     */
    #[Computed]
    public function deploymentsByTask(): Collection
    {
        $tasks = collect($this->tasks->items())->filter(fn (YakTask $task) => $task->branch_name !== null);

        if ($tasks->isEmpty()) {
            return collect();
        }

        return BranchDeployment::query()
            ->with('repository')
            ->whereIn('branch_name', $tasks->pluck('branch_name')->unique()->values())
            ->whereNotIn('status', [DeploymentStatus::Destroyed, DeploymentStatus::Destroying])
            ->get()
            ->keyBy(fn (BranchDeployment $deployment): string => $deployment->repository->slug . '/' . $deployment->branch_name);
    }

    public function deploymentFor(YakTask $task): ?BranchDeployment
    {
        if ($task->branch_name === null) {
            return null;
        }

        return $this->deploymentsByTask->get($task->repo . '/' . $task->branch_name);
    }

    /**
     * Follow-up descendants for the roots on the current page, grouped by
     * branch_name. Every task in a follow-up chain shares the root's
     * branch_name, so this captures the whole chain (children, grandchildren,
     * ...) in a single query — `$task->followUps` would only get direct kids.
     *
     * @return Collection<string, \Illuminate\Database\Eloquent\Collection<int, YakTask>>
     */
    #[Computed]
    public function descendantsByBranch(): Collection
    {
        $branches = collect($this->tasks->items())->pluck('branch_name')->filter()->unique()->values();

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
     * Poster and preview-GIF URLs for the tasks on the current page, in
     * one query so the table does not issue an artifact lookup per row.
     *
     * @return array<int, array{poster: string, gif: ?string}>
     */
    #[Computed]
    public function previewsByTask(): array
    {
        $taskIds = collect($this->tasks->items())->pluck('id')->all();

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
                'poster' => $preview['poster'] ?? $preview['gif'],
                'gif' => $preview['gif'] ?? null,
            ])
            ->all();
    }

    /**
     * @return Builder<YakTask>
     */
    protected function scopedQuery(string $tab): Builder
    {
        return match ($tab) {
            'setup' => YakTask::query()->where('mode', TaskMode::Setup),
            'reviews' => YakTask::query()->where('mode', TaskMode::Review),
            default => YakTask::query()->whereIn('mode', [TaskMode::Fix, TaskMode::Research]),
        };
    }

    #[Computed]
    public function tasksCount(): int
    {
        return $this->scopedQuery('tasks')->whereNull('parent_task_id')->count();
    }

    #[Computed]
    public function setupCount(): int
    {
        return $this->scopedQuery('setup')->whereNull('parent_task_id')->count();
    }

    #[Computed]
    public function reviewsCount(): int
    {
        return $this->scopedQuery('reviews')->whereNull('parent_task_id')->count();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /**
     * Shows the "Getting started" card on the Tasks page when the
     * installation looks bare (no repos or no tasks) AND the current
     * user hasn't dismissed the card yet. Self-hides once the user
     * has both at least one repo configured and at least one task
     * completed, so admins don't see it forever.
     */
    #[Computed]
    public function showSetupCard(): bool
    {
        $user = auth()->user();

        if ($user === null || $user->has_seen_setup_card_at !== null) {
            return false;
        }

        return Repository::count() === 0 || YakTask::count() === 0;
    }

    /**
     * The setup checklist items shown inside the "Getting started"
     * card. Each item knows whether it's done and where the user
     * should click to complete it.
     *
     * @return list<array{label: string, description: string, done: bool, url: string, external: bool}>
     */
    #[Computed]
    public function setupChecklist(): array
    {
        $anyRepo = Repository::count() > 0;
        $registry = app(ChannelRegistry::class);
        $anyChannelEnabled = collect(['slack', 'linear', 'sentry', 'github', 'drone'])
            ->contains(fn (string $channel) => $registry->for($channel)?->enabled() ?? false);
        $anyTask = YakTask::count() > 0;

        return [
            [
                'label' => 'Add your first repository',
                'description' => 'Yak clones it and dispatches a setup task automatically.',
                'done' => $anyRepo,
                'url' => route('repos.create'),
                'external' => false,
            ],
            [
                'label' => 'Connect a channel',
                'description' => 'Slack, Linear, or Sentry — tasks come in from configured channels.',
                'done' => $anyChannelEnabled,
                'url' => Docs::url('channels'),
                'external' => true,
            ],
            [
                'label' => 'Send your first task',
                'description' => 'Mention @yak in Slack or assign a Linear issue to Yak.',
                'done' => $anyTask,
                'url' => Docs::url('channels.slack'),
                'external' => true,
            ],
        ];
    }

    public function dismissSetupCard(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $user->forceFill(['has_seen_setup_card_at' => now()])->save();

        unset($this->showSetupCard);
    }

    public function clearFilters(): void
    {
        $this->status = '';
        $this->source = '';
        $this->repo = '';
        $this->pr = '';
        $this->resetPage();
    }

    public function updatedPr(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function repos(): array
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
    #[Computed]
    public function sources(): array
    {
        return YakTask::query()
            ->whereNotNull('source')
            ->distinct()
            ->pluck('source')
            ->sort()
            ->values()
            ->all();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    public function updatedRepo(): void
    {
        $this->resetPage();
    }

    public static function statusBadgeClasses(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => 'bg-[rgba(107,143,163,0.12)] text-[#6b8fa3]',
            TaskStatus::Running => 'bg-[rgba(143,179,196,0.12)] text-[#8fb3c4] animate-pulse',
            TaskStatus::AwaitingClarification => 'bg-[rgba(212,145,94,0.12)] text-[#d4915e]',
            TaskStatus::AwaitingCi => 'bg-[rgba(143,179,196,0.12)] text-[#8fb3c4]',
            TaskStatus::Retrying => 'bg-[rgba(212,145,94,0.12)] text-[#d4915e]',
            TaskStatus::Success => 'bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]',
            TaskStatus::Failed => 'bg-[rgba(184,84,80,0.12)] text-[#b85450]',
            TaskStatus::Expired => 'bg-[rgba(200,184,154,0.12)] text-[#c8b89a]',
            TaskStatus::Cancelled => 'bg-[rgba(200,184,154,0.12)] text-[#c8b89a]',
        };
    }

    /**
     * Fill colour for the compact status dot in the task table. The full
     * label lives in the dot's tooltip and visually-hidden text.
     */
    public static function statusDotClasses(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => 'bg-[#6b8fa3]',
            TaskStatus::Running => 'bg-[#8fb3c4] animate-pulse',
            TaskStatus::AwaitingClarification => 'bg-[#d4915e]',
            TaskStatus::AwaitingCi => 'bg-[#8fb3c4] animate-pulse',
            TaskStatus::Retrying => 'bg-[#d4915e] animate-pulse',
            TaskStatus::Success => 'bg-[#7a8c5e]',
            TaskStatus::Failed => 'bg-[#b85450]',
            TaskStatus::Expired => 'bg-[#c8b89a]',
            TaskStatus::Cancelled => 'bg-[#e0b84c]',
        };
    }

    public static function statusLabel(TaskStatus $status): string
    {
        return str_replace('_', ' ', $status->value);
    }

    /**
     * Badge classes for the PR lifecycle column.
     */
    public static function prStateBadgeClasses(string $state): string
    {
        return match ($state) {
            'merged' => 'bg-[rgba(139,92,246,0.12)] text-[#7c5cbf]',
            'closed' => 'bg-[rgba(184,84,80,0.12)] text-[#b85450]',
            default => 'bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]',
        };
    }

    /**
     * Relative age shown in the table, e.g. "3h ago". The tooltip carries
     * the absolute timestamps.
     */
    public static function formatAge(?CarbonInterface $timestamp): string
    {
        if ($timestamp === null) {
            return '—';
        }

        return $timestamp->diffForHumans(['short' => true, 'parts' => 1]);
    }

    public static function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null || $durationMs === 0) {
            return '—';
        }

        $minutes = (int) round($durationMs / 60000);

        if ($minutes < 1) {
            return '1m';
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }
}
