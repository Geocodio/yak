<?php

namespace App\Livewire\Deployments;

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Deployment')]
class DeploymentShow extends Component
{
    public BranchDeployment $deployment;

    public function mount(BranchDeployment $deployment): void
    {
        $this->deployment = $deployment->load('repository');
    }

    /**
     * @return Collection<int, DeploymentLog>
     */
    #[Computed]
    public function recentLogs(): Collection
    {
        return $this->deployment->logs()
            ->latest('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Faster polling while the deployment is actively transitioning so
     * the activity log feels responsive; back off on settled states.
     */
    #[Computed]
    public function pollInterval(): string
    {
        return match ($this->deployment->status) {
            DeploymentStatus::Pending,
            DeploymentStatus::Starting,
            DeploymentStatus::Destroying => '2s',
            default => '15s',
        };
    }

    public function rebuild(): void
    {
        RebuildDeploymentJob::dispatch($this->deployment->id);
        session()->flash('status', 'Rebuild queued.');
    }

    public function destroy(): void
    {
        DestroyDeploymentJob::dispatch($this->deployment->id);
        session()->flash('status', 'Destroy queued.');
    }

    public function render()
    {
        return view('livewire.deployments.deployment-show');
    }
}
