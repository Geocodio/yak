<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Jobs\SetupYakJob;
use App\Models\Artifact;
use App\Models\TaskLog;
use App\Models\YakTask;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Task Detail')]
class TaskDetail extends Component
{
    public YakTask $task;

    public bool $showDebug = false;

    /**
     * @var array<int, bool>
     */
    public array $expandedLogs = [];

    public function mount(YakTask $task): void
    {
        $this->task = $task;
    }

    public function retry(): void
    {
        if (! $this->canRetry()) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Pending,
            'error_log' => null,
            'result_summary' => null,
            'cost_usd' => 0,
            'duration_ms' => 0,
            'num_turns' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $job = match ($this->task->mode) {
            TaskMode::Setup => new SetupYakJob($this->task),
            default => new RunYakJob($this->task),
        };

        dispatch($job);

        Flux::toast('Task re-queued.');
    }

    #[Computed]
    public function canRetry(): bool
    {
        return in_array($this->task->status, [
            TaskStatus::Failed,
            TaskStatus::Expired,
        ]);
    }

    public function toggleDebug(): void
    {
        $this->showDebug = ! $this->showDebug;
    }

    public function toggleLog(int $index): void
    {
        $this->expandedLogs[$index] = ! ($this->expandedLogs[$index] ?? false);
    }

    /**
     * @return Collection<int, TaskLog>
     */
    #[Computed]
    public function logs(): Collection
    {
        return $this->task->logs()->orderBy('created_at')->get();
    }

    /**
     * @return Collection<int, Artifact>
     */
    #[Computed]
    public function screenshots(): Collection
    {
        return $this->task->artifacts()->where('type', 'screenshot')->get();
    }

    /**
     * @return Collection<int, Artifact>
     */
    #[Computed]
    public function videos(): Collection
    {
        return $this->task->artifacts()->where('type', 'video')->get();
    }

    #[Computed]
    public function researchArtifact(): ?Artifact
    {
        return $this->task->artifacts()->where('type', 'research')->first();
    }

    public static function statusDotColor(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => '#6b8fa3',
            TaskStatus::Running => '#8fb3c4',
            TaskStatus::AwaitingClarification => '#d4915e',
            TaskStatus::AwaitingCi => '#8fb3c4',
            TaskStatus::Retrying => '#d4915e',
            TaskStatus::Success => '#7a8c5e',
            TaskStatus::Failed => '#b85450',
            TaskStatus::Expired => '#c8b89a',
        };
    }

    public static function logLevelColor(string $level): string
    {
        return match ($level) {
            'warning' => '#d4915e',
            'error' => '#b85450',
            default => '#6b8fa3',
        };
    }

    #[Computed]
    public function pollInterval(): string
    {
        return $this->isActiveStatus() ? '5s' : '15s';
    }

    public function isActiveStatus(): bool
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        return in_array($status, [
            TaskStatus::Running,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ]);
    }

    public function clarificationTtl(): ?string
    {
        /** @var TaskStatus $status */
        $status = $this->task->status;

        if ($status !== TaskStatus::AwaitingClarification) {
            return null;
        }

        /** @var Carbon|null $expiresAt */
        $expiresAt = $this->task->clarification_expires_at;

        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return 'Expired';
        }

        return $expiresAt->diffForHumans();
    }

    public function isResearchTask(): bool
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        return $mode === TaskMode::Research;
    }

    public function isFixTask(): bool
    {
        /** @var TaskMode $mode */
        $mode = $this->task->mode;

        return $mode === TaskMode::Fix;
    }
}
