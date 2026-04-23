<form wire:submit="save" class="space-y-4">
    <flux:heading size="lg">Preview manifest</flux:heading>

    <flux:input wire:model="port" label="Port" type="number" />
    <flux:input wire:model="healthProbePath" label="Health probe path" />
    <flux:textarea wire:model="coldStart" label="Cold start command" rows="3" />
    <flux:textarea wire:model="checkoutRefresh" label="Checkout refresh command" rows="3" />
    <flux:input wire:model="wakeTimeoutSeconds" label="Wake timeout (seconds)" type="number" />

    <flux:button type="submit">Save</flux:button>

    @if (session('status'))
        <flux:callout variant="success">{{ session('status') }}</flux:callout>
    @endif
</form>
