<?php

namespace App\Livewire\Deployments;

use App\Models\BranchDeployment;
use App\Services\DeploymentShareTokens;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

class PublicShareToggle extends Component
{
    public BranchDeployment $deployment;

    #[Validate('integer|min:1')]
    public int $expiresInDays = 7;

    public ?string $generatedUrl = null;

    public function mount(BranchDeployment $deployment): void
    {
        $this->deployment = $deployment;
        $this->expiresInDays = (int) config('yak.deployments.share.default_days', 7);
    }

    public function mint(DeploymentShareTokens $tokens): void
    {
        $this->validate();
        $token = $tokens->mint($this->deployment, $this->expiresInDays);
        $this->deployment->refresh();
        $this->generatedUrl = "https://{$this->deployment->hostname}/_share/{$token}/";
    }

    public function revoke(DeploymentShareTokens $tokens): void
    {
        $tokens->revoke($this->deployment);
        $this->deployment->refresh();
        $this->generatedUrl = null;
    }

    public function clearShownToken(): void
    {
        $this->generatedUrl = null;
    }

    public function render(): View
    {
        return view('livewire.deployments.public-share-toggle');
    }
}
