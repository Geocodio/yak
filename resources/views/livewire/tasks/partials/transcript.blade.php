{{--
    The transcript overlay: a near full-bleed two-pane view of one run's
    activity log. Left rail lists the entries (same markup as the sidebar
    preview strip); right pane shows the selected entry in full.

    Sized by an explicit inset rather than shrink-wrapping to content, so
    it is the same size for every entry — the old flyout resized itself
    per entry, which read as jitter while stepping.

    Expects: $task (App\Models\YakTask, root task), $visibleAttempt (int),
    $expandedGroups (array<int, bool>), $logFilter (string),
    $logSearch (string).
--}}
@php $keyPrefix = 'transcript-'; @endphp
<flux:modal
    wire:model.self="transcriptOpen"
    name="transcript"
    :closable="false"
    class="h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none overflow-hidden rounded-2xl !bg-yak-cream !p-0"
>
    @php
        $entry = $this->transcriptLog;
        $position = $this->transcriptPosition();
        $isLive = $this->isActiveStatus();
        // Follow the tail only while the run is in flight and the reader
        // has not pinned an entry to read.
        $follow = $isLive && ! $this->transcriptPinned;
    @endphp

    <div
        class="flex h-full flex-col"
        data-testid="transcript-overlay"
        x-data="{
            step(direction) {
                const el = document.activeElement;
                if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
                if (el && el.isContentEditable) return;
                direction < 0 ? $wire.previousLog() : $wire.nextLog();
            },
            focusSearch(event) {
                const el = document.activeElement;
                if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
                event.preventDefault();
                $el.querySelector('[data-testid=log-search] input, input[data-testid=log-search]')?.focus();
            },
        }"
        @keydown.window.arrow-left="step(-1)"
        @keydown.window.arrow-right="step(1)"
        @keydown.window.k="step(-1)"
        @keydown.window.j="step(1)"
        @keydown.window.slash="focusSearch($event)"
    >
        {{-- Bar: what you're looking at, which run/attempt, and the way out --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-[rgba(200,184,154,0.5)] px-5 py-3">
            <h2 class="text-sm font-semibold text-yak-slate">Transcript</h2>
            <span class="truncate text-xs text-yak-blue">{{ $task->description_summary ?: \Illuminate\Support\Str::limit((string) $task->description, 70) }}</span>

            @if($this->chainRuns->count() > 1)
                <div class="flex flex-wrap gap-1.5" data-testid="transcript-run-picker">
                    @foreach($this->chainRuns as $runIndex => $run)
                        @php $isFocusedRun = $this->focusedRun->is($run); @endphp
                        <button
                            type="button"
                            wire:key="transcript-run-{{ $run->id }}"
                            wire:click="focusRun({{ $run->id }})"
                            class="cursor-pointer rounded-lg border px-2 py-0.5 text-[11px] font-medium transition-colors {{ $isFocusedRun ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.6)]' }}"
                        >
                            Run {{ $runIndex + 1 }}
                        </button>
                    @endforeach
                </div>
            @endif

            @if(count($this->availableAttempts) > 0)
                <div class="flex flex-wrap items-center gap-1.5" data-testid="transcript-attempt-selector">
                    <span class="text-[11px] text-yak-tan">Attempt</span>
                    @foreach($this->availableAttempts as $attempt)
                        <button
                            type="button"
                            wire:key="transcript-attempt-{{ $attempt }}"
                            wire:click="selectAttempt({{ $attempt }})"
                            class="cursor-pointer rounded-md border px-1.5 py-0.5 text-[11px] font-medium transition-colors {{ $visibleAttempt === $attempt ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.6)]' }}"
                        >
                            #{{ $attempt }}
                        </button>
                    @endforeach
                </div>
            @endif

            <button
                type="button"
                wire:click="closeTranscript"
                class="ml-auto cursor-pointer rounded-lg border border-[rgba(200,184,154,0.4)] px-2.5 py-1 text-xs font-medium text-yak-blue transition-colors hover:bg-[rgba(245,240,232,0.7)] hover:text-yak-slate"
                data-testid="transcript-close"
            >
                Close <span class="ml-1 font-mono text-[10px] text-yak-tan">esc</span>
            </button>
        </div>

        <div class="flex min-h-0 flex-1 gap-4 p-4">
            {{-- Left rail: the entries --}}
            <div
                class="flex w-[300px] shrink-0 flex-col rounded-xl border border-[rgba(200,184,154,0.4)] bg-white p-3"
                x-data="activityFollow({{ $follow ? 'true' : 'false' }})"
            >
                @include('livewire.tasks.partials.log-controls')

                <div class="relative min-h-0 flex-1">
                    <div x-ref="logList" class="h-full overflow-y-auto pr-1" @scroll.passive="onScroll()">
                        @include('livewire.tasks.partials.log-rows')
                    </div>

                    @if($isLive)
                    <button
                        type="button"
                        x-show="!following"
                        x-cloak
                        x-transition.opacity
                        @click="jumpToLatest()"
                        class="absolute bottom-3 right-3 inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-yak-orange px-3 py-1.5 text-xs font-medium text-white shadow-lg transition-colors hover:bg-yak-orange-warm"
                        data-testid="transcript-jump-to-latest"
                    >
                        <flux:icon.arrow-down class="!size-3.5" />
                        <span>Jump to latest</span>
                    </button>
                    @endif
                </div>
            </div>

            {{-- Right pane: the selected entry --}}
            <div class="flex min-w-0 flex-1 flex-col gap-3">
                @if($entry)
                    @php
                        $logType = $entry->metadata['type'] ?? null;
                        $isToolUse = $logType === 'tool_use';
                        $isPrompt = $logType === 'prompt';
                        $hasOutput = $isToolUse && isset($entry->metadata['output']);
                        $hasToolInput = $isToolUse && ! empty($entry->metadata['input']);
                        // Tool and prompt entries have a summary line worth a
                        // heading; a plain assistant message IS the body.
                        $showHeading = $isToolUse || $isPrompt;
                    @endphp

                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1.5" data-testid="transcript-stepper">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="chevron-left"
                                wire:click="previousLog"
                                :disabled="$position !== null && $position['position'] === 1"
                                data-testid="log-prev"
                            >
                                <span class="sr-only">Previous step</span>
                            </flux:button>
                            <span class="whitespace-nowrap font-mono text-xs text-yak-blue" data-testid="log-position">
                                @if($position)
                                    Step {{ $position['position'] }} of {{ $position['total'] }}
                                @else
                                    Hidden by the current filter
                                @endif
                            </span>
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="chevron-right"
                                wire:click="nextLog"
                                :disabled="$position !== null && $position['position'] === $position['total']"
                                data-testid="log-next"
                            >
                                <span class="sr-only">Next step</span>
                            </flux:button>
                        </div>

                        @if($showHeading)
                            <h3 class="min-w-0 flex-1 truncate text-base font-semibold text-yak-slate" data-testid="transcript-heading">
                                {{ \App\Support\Markdown::toPlainText($entry->message, 160) }}
                            </h3>
                        @endif

                        <span class="ml-auto hidden font-mono text-[10px] text-yak-tan sm:inline">&larr; &rarr; or j / k &middot; / to search</span>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                    <div class="space-y-3 rounded-xl bg-[#2b3640] p-4">
                        @if($isPrompt)
                            <x-log-block label="User prompt" tone="text-yak-orange-warm" :text="$entry->metadata['prompt'] ?? ''" />
                            <x-log-block label="System prompt" tone="text-yak-orange-warm" :text="$entry->metadata['system_prompt'] ?? ''" />
                            <div class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-[11px] text-[#a8a8a8]">
                                <div><span class="text-[#8a8a8a]">model:</span> {{ $entry->metadata['model'] ?? '-' }}</div>
                                <div><span class="text-[#8a8a8a]">max_turns:</span> {{ $entry->metadata['max_turns'] ?? '-' }}</div>
                                <div><span class="text-[#8a8a8a]">max_budget_usd:</span> {{ $entry->metadata['max_budget_usd'] ?? '-' }}</div>
                                <div><span class="text-[#8a8a8a]">resume_session_id:</span> {{ $entry->metadata['resume_session_id'] ?? '-' }}</div>
                            </div>
                        @else
                            @if($hasToolInput)
                                @php $isBash = ($entry->metadata['tool'] ?? null) === 'Bash'; @endphp
                                @if($isBash && isset($entry->metadata['input']['command']))
                                    <x-log-block label="Command" tone="text-yak-green" :text="$entry->metadata['input']['command']" class="text-[#f5e9c9]" />
                                    @if(! empty($entry->metadata['input']['description']))
                                        <div class="font-mono text-[11px] italic text-[#8a8a8a]"># {{ $entry->metadata['input']['description'] }}</div>
                                    @endif
                                @else
                                    <x-log-block
                                        :label="$isBash ? 'Command' : 'Input'"
                                        tone="text-yak-green"
                                        :text="json_encode($entry->metadata['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)"
                                    />
                                @endif
                            @endif
                            @if($hasOutput)
                                <x-log-block :label="$hasToolInput ? 'Output' : null" tone="text-yak-blue" :text="$entry->metadata['output']" />
                            @elseif(! $hasToolInput)
                                <x-markdown
                                    :text="$entry->message"
                                    class="prose-invert leading-relaxed !text-[#d4d4d4] prose-headings:!text-white prose-strong:!text-white"
                                    data-testid="transcript-message"
                                />
                            @endif
                        @endif
                    </div>

                    {{-- Sits directly under the block it describes, rather than
                         pinned to the foot of the viewport. --}}
                    <div class="mt-3 flex flex-wrap items-center gap-4 font-mono text-[11px] text-yak-blue">
                        <span>{{ $entry->created_at->format('M j, Y g:i:s A') }}</span>
                        @if($isToolUse)
                            <span>tool &middot; {{ $entry->metadata['tool'] ?? 'tool' }}</span>
                        @endif
                        @if($entry->metadata['is_error'] ?? false)
                            <span class="text-yak-danger">errored</span>
                        @endif
                    </div>
                    </div>
                @else
                    <div class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-[rgba(200,184,154,0.5)] text-sm text-yak-tan" data-testid="transcript-empty">
                        Pick an entry on the left to see it in full.
                    </div>
                @endif
            </div>
        </div>
    </div>
</flux:modal>
