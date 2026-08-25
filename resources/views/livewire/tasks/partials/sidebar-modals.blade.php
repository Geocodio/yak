{{--
    Sidebar modals: log drawer (full prompt/input/output for the entry
    clicked in the activity log) and media lightbox (screenshots/videos
    clicked from a thread turn or the latest-media section).

    Rendered exactly once by task-detail.blade.php, outside the sidebar
    partial itself, since the sidebar partial is rendered twice (desktop
    column + mobile drawer) and these modals must not be duplicated.

    Expects: $task (App\Models\YakTask, root task).
--}}
{{-- Log drawer: full prompt/input/output for the entry clicked in the activity log --}}
<flux:modal
    variant="flyout"
    position="right"
    wire:model.self="drawerOpen"
    name="log-drawer"
    class="!min-w-[420px] sm:!min-w-[560px] lg:!min-w-[760px]"
>
    @php $drawerLog = $this->drawerLog; @endphp
    @if($drawerLog)
        @php
            $logType = $drawerLog->metadata['type'] ?? null;
            $isToolUse = $logType === 'tool_use';
            $isPrompt = $logType === 'prompt';
            $hasOutput = $isToolUse && isset($drawerLog->metadata['output']);
            $hasToolInput = $isToolUse && ! empty($drawerLog->metadata['input']);
            // For tool and prompt entries the message is a summary line, so it
            // earns a heading. For plain assistant messages the message IS the
            // body — showing it twice was just noise.
            $showHeading = $isToolUse || $isPrompt;
            $position = $this->drawerPosition();
        @endphp
        <div
            x-data="{
                step(direction) {
                    if (! $wire.drawerOpen) return;
                    const el = document.activeElement;
                    if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
                    if (el && el.isContentEditable) return;
                    direction < 0 ? $wire.previousLog() : $wire.nextLog();
                },
            }"
            @keydown.window.arrow-left="step(-1)"
            @keydown.window.arrow-right="step(1)"
            @keydown.window.k="step(-1)"
            @keydown.window.j="step(1)"
        >
            {{-- Stepper: move between entries without closing and re-finding
                 your place in the activity list. --}}
            <div class="mb-4 flex items-center gap-2" data-testid="log-stepper">
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
                <span class="font-mono text-xs text-yak-blue" data-testid="log-position">
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
                <span class="ml-auto hidden font-mono text-[10px] text-yak-tan sm:inline">
                    &larr; &rarr; or j / k
                </span>
            </div>

            <div class="mb-4">
                @if($showHeading)
                    <flux:heading size="lg" data-testid="log-drawer-heading">
                        {{ \App\Support\Markdown::toPlainText($drawerLog->message, 160) }}
                    </flux:heading>
                @endif
                <div class="mt-1 font-mono text-xs text-yak-tan">{{ $drawerLog->created_at->format('M j, Y g:i:s A') }}</div>
            </div>

            <div class="space-y-3 rounded-xl bg-[#2b3640] p-4">
                @if($isPrompt)
                    <x-log-block label="User prompt" tone="text-yak-orange-warm" :text="$drawerLog->metadata['prompt'] ?? ''" />
                    <x-log-block label="System prompt" tone="text-yak-orange-warm" :text="$drawerLog->metadata['system_prompt'] ?? ''" />
                    <div class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-[11px] text-[#a8a8a8]">
                        <div><span class="text-[#8a8a8a]">model:</span> {{ $drawerLog->metadata['model'] ?? '-' }}</div>
                        <div><span class="text-[#8a8a8a]">max_turns:</span> {{ $drawerLog->metadata['max_turns'] ?? '-' }}</div>
                        <div><span class="text-[#8a8a8a]">max_budget_usd:</span> {{ $drawerLog->metadata['max_budget_usd'] ?? '-' }}</div>
                        <div><span class="text-[#8a8a8a]">resume_session_id:</span> {{ $drawerLog->metadata['resume_session_id'] ?? '-' }}</div>
                    </div>
                @else
                    @if($hasToolInput)
                        @php $isBash = ($drawerLog->metadata['tool'] ?? null) === 'Bash'; @endphp
                        @if($isBash && isset($drawerLog->metadata['input']['command']))
                            <x-log-block
                                label="Command"
                                tone="text-yak-green"
                                :text="$drawerLog->metadata['input']['command']"
                                class="text-[#f5e9c9]"
                            />
                            @if(! empty($drawerLog->metadata['input']['description']))
                                <div class="font-mono text-[11px] italic text-[#8a8a8a]"># {{ $drawerLog->metadata['input']['description'] }}</div>
                            @endif
                        @else
                            <x-log-block
                                :label="$isBash ? 'Command' : 'Input'"
                                tone="text-yak-green"
                                :text="json_encode($drawerLog->metadata['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)"
                            />
                        @endif
                    @endif
                    @if($hasOutput)
                        <x-log-block
                            :label="$hasToolInput ? 'Output' : null"
                            tone="text-yak-blue"
                            :text="$drawerLog->metadata['output']"
                        />
                    @elseif(! $hasToolInput)
                        <x-markdown
                            :text="$drawerLog->message"
                            class="prose-invert leading-relaxed !text-[#d4d4d4] prose-headings:!text-white prose-strong:!text-white"
                            data-testid="log-drawer-message"
                        />
                    @endif
                @endif
            </div>
        </div>
    @endif
</flux:modal>

{{-- Media lightbox: screenshots/videos clicked from a thread turn or the
latest-media section above. Videos additionally surface the Reviewer/Director
cut picker + generation button, absorbed from the old video-walkthrough card. --}}
<flux:modal wire:model.self="lightboxOpen" name="media-lightbox" class="!max-w-2xl" data-testid="media-lightbox">
    @php $lightboxArtifact = $this->lightboxArtifact; @endphp
    @if($lightboxArtifact)
        <div class="mb-3">
            <flux:heading size="lg">{{ $lightboxArtifact->filename }}</flux:heading>
        </div>

        @if($lightboxArtifact->type === 'video')
            <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                <video controls preload="metadata" class="w-full" src="{{ $lightboxArtifact->signedUrl() }}"></video>
            </div>

            <div class="mt-4 border-t border-[rgba(200,184,154,0.3)] pt-4">
                @if($task->mode !== \App\Enums\TaskMode::Review)
                @if($this->reviewerCut)
                    @php $reviewerUrl = $this->reviewerCut->signedUrl(); @endphp
                    <div class="mb-3 overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                        <video controls preload="metadata" class="w-full" src="{{ $reviewerUrl }}"></video>
                        <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">
                            <a href="{{ $reviewerUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">Reviewer Cut</a>
                        </div>
                    </div>
                @endif

                @if($this->directorCutStatus === 'ready' && $this->directorCut)
                    @php $directorUrl = $this->directorCut->signedUrl(); @endphp
                    <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                        <video controls preload="metadata" class="w-full" src="{{ $directorUrl }}"></video>
                        <div class="bg-yak-cream-dark px-3 py-2 text-xs text-yak-blue">
                            <a href="{{ $directorUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">Director's Cut</a>
                        </div>
                    </div>
                @elseif($this->directorCutStatus === 'queued' || $this->directorCutStatus === 'rendering')
                    <div class="flex items-center gap-3 text-sm text-yak-blue" data-testid="director-cut-progress">
                        <flux:icon.loading variant="mini" class="size-4" />
                        <span>{{ $this->directorCutStatus === 'queued' ? 'Queued…' : "Rendering Director's Cut…" }}</span>
                    </div>
                @elseif($this->directorCutStatus === 'failed')
                    <div class="flex items-center gap-3 text-sm text-yak-danger" data-testid="director-cut-failed">
                        <span>Director's Cut render failed.</span>
                        <flux:button variant="ghost" size="sm" wire:click="generateDirectorCut">Retry</flux:button>
                    </div>
                @elseif($this->canGenerateDirectorCut)
                    <flux:button variant="primary" icon="sparkles" wire:click="generateDirectorCut" data-testid="generate-director-cut">
                        Generate Director's Cut
                    </flux:button>
                    <p class="mt-2 text-xs text-yak-blue">Spins up a fresh sandbox against the PR branch. Takes ~2–3 min.</p>
                @endif
                @endif
            </div>
        @else
            <a href="{{ $lightboxArtifact->signedUrl() }}" target="_blank" rel="noopener noreferrer" class="block">
                <img src="{{ $lightboxArtifact->signedUrl() }}" alt="{{ $lightboxArtifact->filename }}" class="w-full rounded-[14px] border border-[rgba(200,184,154,0.4)] object-contain" />
            </a>
        @endif
    @endif
</flux:modal>
