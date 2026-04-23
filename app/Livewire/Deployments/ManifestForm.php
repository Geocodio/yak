<?php

namespace App\Livewire\Deployments;

use App\Models\Repository;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ManifestForm extends Component
{
    public Repository $repository;

    #[Validate('required|integer|min:1|max:65535')]
    public int $port = 80;

    #[Validate('required|string|starts_with:/')]
    public string $healthProbePath = '/';

    #[Validate('nullable|string')]
    public string $coldStart = '';

    #[Validate('nullable|string')]
    public string $checkoutRefresh = '';

    #[Validate('integer|min:1')]
    public int $wakeTimeoutSeconds = 120;

    public function mount(Repository $repository): void
    {
        $this->repository = $repository;
        $m = $repository->preview_manifest ?? [];
        $this->port = (int) ($m['port'] ?? config('yak.deployments.default_port'));
        $this->healthProbePath = (string) ($m['health_probe_path'] ?? '/');
        $this->coldStart = (string) ($m['cold_start'] ?? '');
        $this->checkoutRefresh = (string) ($m['checkout_refresh'] ?? '');
        $this->wakeTimeoutSeconds = (int) ($m['wake_timeout_seconds'] ?? 120);
    }

    public function save(): void
    {
        $this->validate();

        $this->repository->update([
            'preview_manifest' => [
                'port' => $this->port,
                'health_probe_path' => $this->healthProbePath,
                'cold_start' => $this->coldStart,
                'checkout_refresh' => $this->checkoutRefresh,
                'wake_timeout_seconds' => $this->wakeTimeoutSeconds,
            ],
        ]);

        session()->flash('status', 'Preview manifest saved.');
    }

    public function render()
    {
        return view('livewire.deployments.manifest-form');
    }
}
