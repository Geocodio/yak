<div>
    <div class="flex items-center justify-between mb-8">
        <h1 class="text-2xl font-semibold text-yak-slate">Health</h1>
        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshAll">
            Refresh all
        </flux:button>
    </div>

    {{-- System checks --}}
    <section class="mb-8" aria-labelledby="system-section-heading">
        <h2 id="system-section-heading" class="text-xs font-semibold tracking-wider uppercase text-yak-tan mb-3 pl-2">
            System
        </h2>
        <div class="bg-white border border-yak-tan/40 rounded-[28px] shadow-yak overflow-hidden">
            @foreach ($this->systemCheckIds() as $id)
                <livewire:health-row :check-id="$id" :key="'system-' . $id" />
            @endforeach
        </div>
    </section>

    {{-- Channel checks --}}
    @if (count($this->channelCheckIds()) > 0)
        <section aria-labelledby="channels-section-heading">
            <h2 id="channels-section-heading" class="text-xs font-semibold tracking-wider uppercase text-yak-tan mb-3 pl-2">
                Channels
            </h2>
            <div class="bg-white border border-yak-tan/40 rounded-[28px] shadow-yak overflow-hidden">
                @foreach ($this->channelCheckIds() as $id)
                    <livewire:health-row :check-id="$id" :key="'channel-' . $id" />
                @endforeach
            </div>
        </section>
    @endif
</div>
