<section class="w-full">
    <x-settings.layout
        :heading="__('Skills')"
        :subheading="__('Install and manage Claude Code plugins for the Yak agent.')"
        wide
    >
        <flux:callout variant="secondary" icon="information-circle" class="mb-6"
            heading="Plugins are shared across all Yak users. Changes take effect on the next task run, not mid-flight." />

        {{-- Toolbar --}}
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live.debounce.200ms="search"
                type="search"
                icon="magnifying-glass"
                placeholder="Search plugins…"
                class="min-w-[220px] flex-1"
            />

            <div class="flex items-center gap-1 rounded-xl bg-yak-cream-dark p-[3px] text-[13px]">
                @foreach (['all' => 'All', 'installed' => 'Installed', 'bundled' => 'Bundled', 'available' => 'Available'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('filter', @js($value))"
                        @class([
                            'rounded-lg px-3 py-1.5 font-medium',
                            'bg-white text-yak-slate shadow-sm' => $filter === $value,
                            'text-yak-blue hover:text-yak-slate' => $filter !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            <flux:button variant="primary" icon="plus" wire:click="$set('showInstallFromUrl', true)">
                {{ __('Install from URL') }}
            </flux:button>
        </div>

        {{-- Installed --}}
        @if (in_array($filter, ['all', 'installed'], true))
            <div class="skills-section-title">
                <span>Installed</span>
                <span class="skills-count">{{ $this->installed->count() }}</span>
            </div>

            @if ($this->installed->isEmpty())
                <p class="text-sm text-yak-blue">No plugins installed yet.</p>
            @else
                <div class="skills-grid">
                    @foreach ($this->installed as $plugin)
                        @php($source = $this->marketplaces->firstWhere('name', $plugin->marketplace))
                        <div class="plugin-card" wire:key="installed-{{ $plugin->key() }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="text-[15px] font-semibold text-yak-slate">{{ $plugin->name }}</div>
                                <span class="font-mono rounded bg-yak-cream px-[6px] py-[2px] text-[11.5px] text-yak-blue whitespace-nowrap">
                                    {{ $plugin->version ? mb_substr($plugin->version, 0, 7) : '—' }}
                                </span>
                            </div>

                            <p class="text-[13.5px] leading-snug text-yak-blue m-0 flex-1">
                                Installed {{ $plugin->installedAt->diffForHumans() }}@if ($plugin->lastUpdated), updated {{ $plugin->lastUpdated->diffForHumans() }}@endif.
                            </p>

                            <div class="mt-1 flex items-center justify-between border-t border-dashed border-yak-tan/40 pt-[10px]">
                                <span class="text-[11px] text-yak-blue opacity-85">
                                    {{ $plugin->marketplace }}
                                </span>
                                <div class="flex items-center gap-3">
                                    <span @class([
                                        'badge',
                                        'badge-success' => $plugin->enabled,
                                        'badge-faint' => ! $plugin->enabled,
                                    ])>
                                        {{ $plugin->enabled ? 'Enabled' : 'Disabled' }}
                                    </span>
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked="{{ $plugin->enabled ? 'true' : 'false' }}"
                                        wire:click="toggle(@js($plugin->key()), @js(! $plugin->enabled))"
                                        @class(['yak-toggle', 'on' => $plugin->enabled])
                                        aria-label="Toggle {{ $plugin->name }}"
                                    ></button>
                                </div>
                            </div>

                            <div class="mt-1 flex items-center justify-end gap-3 text-[12px]">
                                <flux:button size="xs" variant="ghost" wire:click="updatePlugin(@js($plugin->key()))">
                                    Update
                                </flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="uninstall(@js($plugin->key()))"
                                    wire:confirm="Uninstall {{ $plugin->name }}?">
                                    Uninstall
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        {{-- Bundled --}}
        @if (in_array($filter, ['all', 'bundled'], true) && $this->bundled->isNotEmpty())
            <div class="skills-section-title">
                <span>Bundled</span>
                <span class="skills-count">{{ $this->bundled->count() }} · read-only</span>
            </div>

            <div class="rounded-xl border border-yak-tan/40 bg-white px-[14px] py-[6px]">
                @foreach ($this->bundled as $skill)
                    <div class="flex items-center gap-3 border-b border-yak-tan/25 py-3 last:border-b-0">
                        <span class="badge badge-neutral">bundled</span>
                        <span class="text-[13.5px] font-medium text-yak-slate">{{ $skill->name }}</span>
                        <span class="ml-auto truncate text-[12.5px] text-yak-blue opacity-80">{{ $skill->description }}</span>
                    </div>
                @endforeach
                <p class="px-[4px] py-[10px] text-[12px] italic text-yak-blue">
                    Provisioned by Ansible. Edit <code class="rounded bg-yak-cream px-[5px] py-[1px] text-[11px]">ansible/roles/yak-container/tasks/main.yml</code> and redeploy to change.
                </p>
            </div>
        @endif

        {{-- Available --}}
        @if (in_array($filter, ['all', 'available'], true) && $this->available->isNotEmpty())
            <div class="skills-section-title">
                <span>Available</span>
                <span class="skills-count">
                    @if ($this->marketplaces->isNotEmpty())
                        from {{ $this->marketplaces->pluck('name')->join(', ') }}
                    @else
                        no marketplaces configured
                    @endif
                </span>
            </div>

            <div class="skills-grid">
                @foreach ($this->available->take(60) as $plugin)
                    <div class="plugin-card" wire:key="available-{{ $plugin->key() }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <div class="text-[15px] font-semibold text-yak-slate">{{ $plugin->name }}</div>
                                @if ($plugin->link())
                                    <a href="{{ $plugin->link() }}" target="_blank" rel="noopener"
                                        class="text-yak-blue hover:text-yak-orange" aria-label="Plugin source">
                                        <flux:icon.arrow-top-right-on-square class="size-4" />
                                    </a>
                                @endif
                            </div>
                            @if ($plugin->category)
                                <span class="badge badge-neutral">{{ $plugin->category }}</span>
                            @endif
                        </div>
                        <p class="m-0 flex-1 line-clamp-3 text-[13.5px] leading-snug text-yak-blue">
                            {{ $plugin->description }}
                        </p>
                        <div class="mt-1 flex items-center justify-between border-t border-dashed border-yak-tan/40 pt-[10px]">
                            <span class="text-[11px] text-yak-blue opacity-85">{{ $plugin->marketplace }}</span>
                            <flux:button size="xs" variant="filled"
                                wire:click="install(@js($plugin->name), @js($plugin->marketplace))">
                                Install
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($this->available->count() > 60)
                <p class="mt-3 text-[12px] text-yak-blue">
                    Showing 60 of {{ $this->available->count() }}. Use search to narrow results.
                </p>
            @endif
        @endif

        {{-- Marketplaces --}}
        @if ($filter === 'all')
            <div class="skills-section-title">
                <span>Marketplaces</span>
            </div>

            <div class="rounded-xl border border-yak-tan/40 bg-white p-5">
                @forelse ($this->marketplaces as $mp)
                    <div @class([
                        'flex items-center justify-between py-3',
                        'border-t border-yak-tan/25' => ! $loop->first,
                    ])>
                        <div>
                            <div class="text-[14px] font-semibold text-yak-slate">{{ $mp->name }}</div>
                            <div class="mt-[2px] text-[12.5px] text-yak-blue">
                                {{ $mp->source ?: '—' }}
                                @if ($mp->lastUpdated)
                                    · Updated {{ $mp->lastUpdated->diffForHumans() }}
                                @endif
                            </div>
                        </div>
                        <flux:button size="xs" variant="ghost" wire:click="removeMarketplace(@js($mp->name))"
                            wire:confirm="Remove marketplace {{ $mp->name }}?">
                            Remove
                        </flux:button>
                    </div>
                @empty
                    <p class="text-sm text-yak-blue">No marketplaces configured.</p>
                @endforelse

                <form wire:submit.prevent="addMarketplace" class="mt-4 flex items-center gap-2">
                    <flux:input wire:model="newMarketplace" placeholder="github:owner/repo or git URL" class="flex-1" />
                    <flux:button type="submit" variant="filled">Add</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="refreshMarketplaces">Refresh</flux:button>
                </form>
                @error('newMarketplace')
                    <p class="mt-2 text-sm text-yak-danger">{{ $message }}</p>
                @enderror
            </div>
        @endif

        {{-- Install from URL modal --}}
        <flux:modal wire:model.self="showInstallFromUrl" name="install-from-url">
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Install from URL or path</flux:heading>
                    <flux:text class="mt-2">Accepts a git URL (e.g. <code>https://github.com/acme/plugin.git</code>) or an absolute path on the Yak server.</flux:text>
                </div>

                <form wire:submit.prevent="installFromUrl" class="space-y-3">
                    <flux:input wire:model="installUrl" placeholder="https://github.com/owner/plugin.git" autofocus />
                    @error('installUrl')
                        <p class="text-sm text-yak-danger">{{ $message }}</p>
                    @enderror

                    <div class="flex justify-end gap-2">
                        <flux:button type="button" variant="ghost" wire:click="$set('showInstallFromUrl', false)">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Install</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    </x-settings.layout>
</section>
