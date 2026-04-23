<div wire:poll.15s>
    @if ($this->deployment)
        <flux:card class="my-3">
            <flux:heading size="sm">Preview</flux:heading>
            <div class="flex items-center justify-between gap-2 mt-2">
                <a href="https://{{ $this->deployment->hostname }}" target="_blank" rel="noopener" class="text-yak-orange hover:underline">
                    {{ $this->deployment->hostname }}
                </a>
                <flux:badge :color="match($this->deployment->status) {
                    App\Enums\DeploymentStatus::Running => 'green',
                    App\Enums\DeploymentStatus::Failed => 'red',
                    App\Enums\DeploymentStatus::Hibernated => 'amber',
                    default => 'zinc',
                }">{{ $this->deployment->status->value }}</flux:badge>
            </div>
            <a href="{{ route('deployments.show', $this->deployment) }}" wire:navigate class="text-sm text-yak-orange mt-2 inline-block hover:underline">
                Manage deployment &rarr;
            </a>
        </flux:card>
    @endif
</div>
