<?php

namespace App\Livewire\Tasks;

use App\Channel;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use App\Support\Docs;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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

    /**
     * @return LengthAwarePaginator<int, YakTask>
     */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        return $this->scopedQuery($this->tab)
            ->with('repository')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->tab === 'tasks' && $this->source !== '', fn ($query) => $query->where('source', $this->source))
            ->when($this->repo !== '', fn ($query) => $query->where('repo', $this->repo))
            ->latest()
            ->paginate(50);
    }

    /**
     * @return Builder<YakTask>
     */
    protected function scopedQuery(string $tab): Builder
    {
        return match ($tab) {
            'setup' => YakTask::query()->where('mode', TaskMode::Setup),
            default => YakTask::query()->whereIn('mode', [TaskMode::Fix, TaskMode::Research]),
        };
    }

    #[Computed]
    public function tasksCount(): int
    {
        return $this->scopedQuery('tasks')->count();
    }

    #[Computed]
    public function setupCount(): int
    {
        return $this->scopedQuery('setup')->count();
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
        $anyChannelEnabled = collect(['slack', 'linear', 'sentry', 'github', 'drone'])
            ->contains(fn (string $channel) => (new Channel($channel))->enabled());
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
