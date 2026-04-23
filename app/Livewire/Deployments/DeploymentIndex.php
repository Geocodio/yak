<?php

namespace App\Livewire\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Deployments')]
class DeploymentIndex extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'active';

    /**
     * @return LengthAwarePaginator<int, BranchDeployment>
     */
    #[Computed]
    public function deployments(): LengthAwarePaginator
    {
        $query = BranchDeployment::query()->with('repository');

        if ($this->statusFilter === 'active') {
            $query->whereIn('status', [
                DeploymentStatus::Pending->value,
                DeploymentStatus::Starting->value,
                DeploymentStatus::Running->value,
                DeploymentStatus::Hibernated->value,
                DeploymentStatus::Failed->value,
            ]);
        } elseif ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return $query->orderByDesc('last_accessed_at')->paginate(25);
    }

    public function render()
    {
        return view('livewire.deployments.deployment-index');
    }
}
