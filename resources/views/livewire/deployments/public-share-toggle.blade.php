<div class="my-3">
    <flux:heading size="sm">Public share link</flux:heading>

    @if ($deployment->public_share_token_hash)
        <p class="text-sm my-2">
            Active share link
            @if ($deployment->public_share_expires_at)
                (expires {{ $deployment->public_share_expires_at->diffForHumans() }})
            @endif
        </p>
        <flux:button wire:click="revoke" variant="danger" wire:confirm="Revoke the current share link?">
            Revoke
        </flux:button>
    @else
        <div class="flex items-end gap-2">
            <flux:input wire:model="expiresInDays" type="number" label="Expires in (days)" />
            <flux:button wire:click="mint">Generate share link</flux:button>
        </div>
    @endif

    @if ($generatedUrl)
        <flux:callout variant="success" class="mt-3">
            <p class="mb-1">Copy this link and share it. It will not be shown again.</p>
            <div class="flex items-center gap-2">
                <flux:input readonly value="{{ $generatedUrl }}" class="font-mono flex-1" />
            </div>
            <flux:button variant="subtle" size="sm" class="mt-2" wire:click="clearShownToken">Done</flux:button>
        </flux:callout>
    @endif
</div>
