<?php

namespace App\Livewire\Repositories;

use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\Repository;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class RebuildAllDeploymentsAction extends Component
{
    public Repository $repository;

    public function mount(Repository $repository): void
    {
        $this->repository = $repository;
    }

    public function rebuildAll(): void
    {
        RebuildRepositoryDeploymentsJob::dispatch($this->repository->id);
        session()->flash('status', 'Bulk rebuild queued for all active deployments.');
    }

    public function render(): View
    {
        return view('livewire.repositories.rebuild-all-deployments-action');
    }
}
