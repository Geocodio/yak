<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-yak-slate">Prompts</h1>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[16rem_minmax(0,1fr)] gap-6">
        {{-- Sidebar prompt list --}}
        <div class="bg-white border border-yak-tan/40 rounded-[28px] shadow-yak overflow-hidden">
            <nav class="flex flex-col py-3" data-test="prompt-sidebar">
                <div class="px-5 pt-2 pb-1 text-[11px] uppercase tracking-wide text-yak-blue/70 font-medium">High-touch</div>
                @foreach ($this->sidebarPrompts as $slug => $meta)
                    @if ($meta['category'] === 'high_touch')
                        <button
                            type="button"
                            wire:click="selectPrompt('{{ $slug }}')"
                            class="flex items-center justify-between w-full px-5 py-2 text-sm text-left transition-colors {{ $selectedSlug === $slug ? 'bg-yak-orange/10 text-yak-orange font-medium border-l-2 border-yak-orange' : 'text-yak-slate hover:bg-yak-cream' }}"
                            data-test="prompt-item-{{ $slug }}"
                        >
                            <span>{{ $meta['label'] }}</span>
                            <span class="text-[10px] uppercase tracking-wide text-yak-blue/60">{{ $meta['type'] }}</span>
                        </button>
                    @endif
                @endforeach

                <div class="px-5 pt-4 pb-1 text-[11px] uppercase tracking-wide text-yak-blue/70 font-medium">Advanced</div>
                @foreach ($this->sidebarPrompts as $slug => $meta)
                    @if ($meta['category'] === 'advanced')
                        <button
                            type="button"
                            wire:click="selectPrompt('{{ $slug }}')"
                            class="flex items-center justify-between w-full px-5 py-2 text-sm text-left transition-colors {{ $selectedSlug === $slug ? 'bg-yak-orange/10 text-yak-orange font-medium border-l-2 border-yak-orange' : 'text-yak-slate/80 hover:bg-yak-cream' }}"
                            data-test="prompt-item-{{ $slug }}"
                        >
                            <span>{{ $meta['label'] }}</span>
                            <span class="text-[10px] uppercase tracking-wide text-yak-blue/60">{{ $meta['type'] }}</span>
                        </button>
                    @endif
                @endforeach
            </nav>
        </div>

        {{-- Editor + preview --}}
        @if ($selectedSlug)
            @php($def = \App\Prompts\PromptDefinitions::for($selectedSlug))
            <div class="flex flex-col gap-4" wire:key="prompt-editor-{{ $selectedSlug }}" x-data="promptEditor()" x-init="$nextTick(() => init())">
                {{-- Toolbar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 bg-white border border-yak-tan/40 rounded-[28px] shadow-yak px-5 py-3">
                    <div class="flex items-center gap-3">
                        <span class="text-base font-semibold text-yak-slate">{{ $def['label'] }}</span>
                        <span class="text-[11px] uppercase tracking-wide px-2 py-0.5 rounded-full bg-yak-blue/10 text-yak-blue">{{ $def['type'] }}</span>
                        @if ($this->isCustomized)
                            <span class="text-[11px] uppercase tracking-wide px-2 py-0.5 rounded-full bg-yak-orange/10 text-yak-orange">Customized</span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="clock" wire:click="openHistory">
                            History
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="arrows-right-left" wire:click="toggleDiff" data-test="toggle-diff">
                            {{ $showDiff ? 'Hide Diff' : 'Diff' }}
                        </flux:button>
                        @if ($this->isCustomized)
                            <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="confirmReset" data-test="reset-button">
                                Reset
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="primary" icon="check" wire:click="save" data-test="save-button">
                            Save
                        </flux:button>
                    </div>
                </div>

                {{-- Available variables --}}
                @if (count($this->availableVariables) > 0)
                    <div class="flex flex-wrap items-center gap-2 px-2">
                        <span class="text-xs text-yak-blue/80 font-medium">Available variables:</span>
                        @foreach ($this->availableVariables as $var)
                            <code class="text-xs px-2 py-0.5 rounded-btn bg-yak-slate/5 text-yak-slate border border-yak-tan/40">${{ $var }}</code>
                        @endforeach
                    </div>
                @endif

                {{-- Editor + preview split --}}
                @if (! $showDiff)
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                        <div class="rounded-[28px] overflow-hidden shadow-yak border border-yak-tan/40" style="min-height: 500px;">
                            <div
                                wire:ignore
                                x-ref="editor"
                                class="h-full min-h-[500px]"
                                data-test="prompt-editor-surface"
                            ></div>
                        </div>

                        <div class="flex flex-col gap-3">
                            <div class="flex items-center justify-between gap-4 px-2">
                                <span class="text-xs text-yak-blue/80 font-medium uppercase tracking-wide">Preview</span>

                                @if (count($this->fixtures) > 1)
                                    <flux:select size="sm" wire:model.live="fixtureIndex" data-test="fixture-select" aria-label="Change sample" class="!w-auto">
                                        @foreach ($this->fixtures as $index => $fixture)
                                            <flux:select.option value="{{ $index }}">{{ $fixture['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @elseif (count($this->fixtures) === 1)
                                    <span class="text-xs text-yak-blue/60">Sample: {{ $this->fixtures[0]['label'] }}</span>
                                @endif
                            </div>

                            @if ($previewOk)
                                <pre class="whitespace-pre-wrap break-words font-mono text-sm text-yak-slate bg-yak-cream/50 border border-yak-tan/40 rounded-[28px] shadow-yak p-5 min-h-[500px]" data-test="prompt-preview">{{ $previewBody }}</pre>
                            @else
                                <pre class="whitespace-pre-wrap break-words font-mono text-sm text-yak-danger bg-yak-danger/5 border border-yak-danger/40 rounded-[28px] p-5 min-h-[500px]" data-test="prompt-preview-error">{{ $previewBody }}</pre>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="rounded-[28px] overflow-hidden shadow-yak border border-yak-tan/40" style="min-height: 500px;">
                        <div
                            wire:ignore
                            x-data="promptDiff()"
                            x-init="$nextTick(() => init())"
                            data-previous="@js($this->versions->first()?->content ?? '')"
                        >
                            <div x-ref="diff" class="h-full min-h-[500px]"></div>
                        </div>
                    </div>
                @endif

                {{-- Validation bar --}}
                <div class="flex flex-col gap-2">
                    @if ($saveMessage)
                        <div class="text-sm {{ $saveError ? 'text-yak-danger' : 'text-yak-green' }}" data-test="save-message">
                            {{ $saveMessage }}
                        </div>
                    @endif
                    @if (count($this->unknownVariables) > 0)
                        <div class="text-sm text-yak-danger">
                            Unknown variables in prompt:
                            @foreach ($this->unknownVariables as $var)
                                <code class="font-mono">${{ $var }}</code>@if (! $loop->last), @endif
                            @endforeach
                        </div>
                    @endif
                    @if (count($this->unusedVariables) > 0)
                        <div class="text-xs text-yak-blue/70">
                            Unused variables:
                            @foreach ($this->unusedVariables as $var)
                                <code class="font-mono">${{ $var }}</code>@if (! $loop->last), @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- History modal --}}
    <flux:modal wire:model.self="showHistory" class="md:w-[560px]">
        <div class="p-6">
            <flux:heading size="lg" class="mb-4">Version history</flux:heading>
            @if (count($this->versions) === 0)
                <div class="text-sm text-yak-blue">No saved versions yet.</div>
            @else
                <div class="flex flex-col divide-y divide-yak-tan/40">
                    @foreach ($this->versions as $version)
                        <button
                            type="button"
                            wire:click="loadVersion({{ $version->id }})"
                            class="flex items-center justify-between py-3 text-left hover:bg-yak-cream/60 px-2 rounded-btn transition-colors"
                            data-test="version-row-{{ $version->version }}"
                        >
                            <span class="text-sm font-medium text-yak-slate">Version {{ $version->version }}</span>
                            <span class="text-xs text-yak-blue/70">{{ $version->created_at?->diffForHumans() }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
            <div class="flex justify-end mt-4">
                <flux:button size="sm" variant="ghost" wire:click="closeHistory">Close</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Reset confirmation --}}
    <flux:modal wire:model.self="showResetConfirm" class="md:w-[460px]">
        <div class="p-6">
            <flux:heading size="lg" class="mb-3">Reset to default?</flux:heading>
            <div class="text-sm text-yak-slate mb-5">
                This discards your customized content and restores the shipped default.
                Saved versions are kept — you can restore an edit from History later.
            </div>
            <div class="flex justify-end gap-2">
                <flux:button size="sm" variant="ghost" wire:click="cancelReset">Cancel</flux:button>
                <flux:button size="sm" variant="danger" wire:click="resetToDefault" data-test="reset-confirm">Reset</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
