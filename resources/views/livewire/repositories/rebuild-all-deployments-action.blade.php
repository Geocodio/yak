<div>
    <flux:button wire:click="rebuildAll" variant="subtle"
        wire:confirm="Rebuild every active deployment for {{ $repository->slug }}? Each container's data will be reset.">
        Rebuild all deployments
    </flux:button>
</div>
