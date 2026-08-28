<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Video walkthroughs')" :subheading="__('How the walkthrough attached to every PR looks. Changes apply to the next render.')" wide>
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            {{-- Form column --}}
            <form wire:submit="save" class="flex flex-col gap-8">
                {{-- Colors --}}
                <div class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Colors') }}</flux:heading>
                        <flux:button variant="ghost" size="sm" type="button" wire:click="resetToDefaults">{{ __('Reset to defaults') }}</flux:button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            'background' => __('Background'),
                            'surface' => __('Chapter card'),
                            'ink' => __('Text'),
                            'muted' => __('Muted text'),
                            'accent' => __('Accent'),
                            'done' => __('Done'),
                        ] as $key => $label)
                            <flux:input
                                wire:model.live.debounce.300ms="colors.{{ $key }}"
                                :label="$label"
                                class="font-mono"
                            >
                                <x-slot:icon>
                                    <span class="size-4 rounded-full border border-zinc-300 dark:border-zinc-600" style="background: {{ $colors[$key] ?? '#ffffff' }}"></span>
                                </x-slot:icon>
                            </flux:input>
                        @endforeach
                    </div>
                    <flux:text size="sm" class="text-zinc-500">{{ __('Accent marks the focused element and the caption rule. Done is the checkmark color on the summary card.') }}</flux:text>
                </div>

                {{-- Fonts --}}
                <div class="flex flex-col gap-3">
                    <flux:heading size="sm">{{ __('Fonts') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach (['display' => __('Display'), 'body' => __('Body'), 'mono' => __('Mono')] as $role => $label)
                            <flux:select wire:model.live="fonts.{{ $role }}" :label="$label">
                                @foreach ($this->fontFamilies as $family)
                                    <flux:select.option value="{{ $family }}">{{ $family }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endforeach
                    </div>
                    <flux:text size="sm" class="text-zinc-500">{{ __('Any Google Fonts family the renderer bundles. The renderer downloads it at render time.') }}</flux:text>
                </div>

                {{-- Logo --}}
                <div class="flex flex-col gap-3">
                    <flux:heading size="sm">{{ __('Logo') }}</flux:heading>
                    <div class="flex flex-wrap items-center gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ __('Theme logo') }}" class="h-10 w-auto" />
                        @else
                            <flux:icon name="photo" class="size-8 text-zinc-400" />
                        @endif
                        <div class="min-w-40 flex-1">
                            <flux:text>{{ $logoUrl ? __('Logo set') : __('No logo') }}</flux:text>
                            <flux:text size="sm" class="text-zinc-500">{{ __('PNG or SVG, up to 512 KB. Shown top-left on the title and summary cards.') }}</flux:text>
                        </div>
                        <flux:input type="file" wire:model="logo" accept="image/png,image/svg+xml" class="max-w-56" />
                        @if ($logoUrl)
                            <flux:button variant="ghost" size="sm" type="button" wire:click="removeLogo">{{ __('Remove') }}</flux:button>
                        @endif
                    </div>
                    <flux:error name="logo" />
                </div>

                {{-- Voiceover (read only) --}}
                <div class="flex flex-col gap-3">
                    <flux:heading size="sm">{{ __('Voiceover') }}</flux:heading>
                    <div class="flex items-center gap-3">
                        <flux:badge :color="$this->voiceoverEnabled ? 'lime' : 'zinc'" size="sm">{{ $this->voiceoverEnabled ? __('On') : __('Off') }}</flux:badge>
                        <flux:text size="sm" class="text-zinc-500">
                            {{ $this->voiceoverEnabled ? __('ElevenLabs key detected.') : __('Set ELEVENLABS_API_KEY to turn voiceover on. Without it, walkthroughs are captions only.') }}
                        </flux:text>
                    </div>
                </div>

                {{-- Public site URL pointer --}}
                <flux:callout icon="information-circle" variant="secondary">
                    {{ __("The browser bar in each video shows the repository's public site URL instead of the sandbox address. Set it per repository under Repositories, the repo, Public site URL.") }}
                </flux:callout>

                <div class="flex items-center gap-3">
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                    @if ($savedAt)
                        <flux:text size="sm" class="text-zinc-500">{{ __('Last saved :when', ['when' => $savedAt]) }}</flux:text>
                    @endif
                </div>
            </form>

            {{-- Preview column (Task 7) --}}
            <div
                class="lg:sticky lg:top-6 lg:self-start"
                data-testid="video-theme-preview-column"
                data-theme="{{ $this->themeJson }}"
            ></div>
        </div>
    </x-settings.layout>
</section>
