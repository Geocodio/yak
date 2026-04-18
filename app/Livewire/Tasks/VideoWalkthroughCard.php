<?php

namespace App\Livewire\Tasks;

use App\Enums\TaskStatus;
use App\Jobs\GenerateDirectorCutJob;
use App\Models\Artifact;
use App\Models\YakTask;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @property-read ?Artifact $reviewerCut
 * @property-read ?Artifact $directorCut
 * @property-read bool $canGenerateDirectorCut
 * @property-read string $directorCutStatus
 */
class VideoWalkthroughCard extends Component
{
    public YakTask $task;

    public function mount(YakTask $task): void
    {
        $this->task = $task;
    }

    #[Computed]
    public function reviewerCut(): ?Artifact
    {
        return $this->task->artifacts()->reviewerCut()->latest('id')->first();
    }

    #[Computed]
    public function directorCut(): ?Artifact
    {
        return $this->task->artifacts()->directorCut()->latest('id')->first();
    }

    /**
     * Task is Success + has an open PR + no in-flight/ready director cut.
     * Presence of a PR is indicated by pr_url being non-empty.
     */
    #[Computed]
    public function canGenerateDirectorCut(): bool
    {
        return $this->task->status === TaskStatus::Success
            && ! empty($this->task->pr_url)
            && in_array($this->task->director_cut_status, [null, 'failed'], true);
    }

    #[Computed]
    public function directorCutStatus(): string
    {
        if ($this->directorCut !== null) {
            return 'ready';
        }

        return $this->task->director_cut_status ?? 'idle';
    }

    public function generateDirectorCut(): void
    {
        if (! $this->canGenerateDirectorCut) {
            return;
        }

        GenerateDirectorCutJob::dispatch($this->task->id);
        $this->task->update(['director_cut_status' => 'queued']);
        $this->task->refresh();
    }

    #[On('artifact-updated')]
    public function refreshFromEvent(): void
    {
        $this->task->refresh();
    }

    public function render()
    {
        return view('livewire.tasks.video-walkthrough-card');
    }
}
