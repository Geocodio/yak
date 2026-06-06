<?php

namespace App\Livewire\Deployments;

use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Support\HibernationDuration;
use App\Support\ReleaseBranch;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Deployment')]
class DeploymentShow extends Component
{
    public BranchDeployment $deployment;

    public bool $longLived = false;

    public string $idleTimeoutInput = '';

    public function mount(BranchDeployment $deployment): void
    {
        $this->deployment = $deployment->load('repository');
        $this->longLived = (bool) $this->deployment->long_lived;
        $this->idleTimeoutInput = HibernationDuration::toShorthand($this->deployment->effectiveIdleMinutes());
    }

    public function updatedLongLived(bool $value): void
    {
        $this->deployment->long_lived = $value;

        if (! $value) {
            $this->deployment->idle_timeout_minutes = null;
        }

        $this->deployment->save();
        $this->idleTimeoutInput = HibernationDuration::toShorthand($this->deployment->effectiveIdleMinutes());

        session()->flash('status', $value ? 'Marked as long-lived.' : 'Reverted to standard hibernation.');
    }

    public function saveIdleTimeout(): void
    {
        $minutes = HibernationDuration::toMinutes($this->idleTimeoutInput);

        if ($minutes === null) {
            $this->addError('idleTimeoutInput', 'Enter a duration like 3d, 12h, or 2w.');

            return;
        }

        $this->deployment->idle_timeout_minutes = $minutes;
        $this->deployment->save();
        $this->idleTimeoutInput = HibernationDuration::toShorthand($minutes);

        session()->flash('status', 'Hibernation timeout updated.');
    }

    #[Computed]
    public function isReleaseBranch(): bool
    {
        return ReleaseBranch::matches($this->deployment->branch_name);
    }

    /**
     * @return Collection<int, DeploymentLog>
     */
    #[Computed]
    public function recentLogs(): Collection
    {
        return $this->deployment->logs()
            ->with('chunks')
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
