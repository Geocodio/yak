<?php

namespace App\Livewire\Tasks;

use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\YakTask;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PreviewWidget extends Component
{
    public YakTask $task;

    public function mount(YakTask $task): void
    {
        $this->task = $task;
    }

    #[Computed]
    public function deployment(): ?BranchDeployment
    {
        if ($this->task->branch_name === null) {
            return null;
        }

        // YakTask has `repo` (slug) not `repository_id`. Look up by slug.
        $repository = Repository::where('slug', $this->task->repo)->first();

        if ($repository === null) {
            return null;
        }

        return BranchDeployment::where('repository_id', $repository->id)
            ->where('branch_name', $this->task->branch_name)
            ->whereNotIn('status', ['destroyed', 'destroying'])
            ->first();
    }

    public function render()
    {
        return view('livewire.tasks.preview-widget');
    }
}
