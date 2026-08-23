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
<flux:modal variant="flyout" position="right" wire:model.self="drawerOpen" name="log-drawer" class="!min-w-[420px] sm:!min-w-[520px]">
    @php $drawerLog = $this->drawerLogIndex !== null ? $this->logs->get($this->drawerLogIndex) : null; @endphp
    @if($drawerLog)
        @php
            $logType = $drawerLog->metadata['type'] ?? null;
            $isToolUse = $logType === 'tool_use';
            $isPrompt = $logType === 'prompt';
            $hasOutput = $isToolUse && isset($drawerLog->metadata['output']);
            $hasToolInput = $isToolUse && ! empty($drawerLog->metadata['input']);
        @endphp
        <div class="mb-4">
            <flux:heading size="lg">{{ $drawerLog->message }}</flux:heading>
            <div class="mt-1 font-mono text-xs text-yak-tan">{{ $drawerLog->created_at->format('M j, Y g:i:s A') }}</div>
        </div>
        <div class="space-y-3 rounded-xl bg-[#2b3640] p-4">
            @if($isPrompt)
                <div>
                    <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-orange-warm">User prompt</div>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $drawerLog->metadata['prompt'] ?? '' }}</pre>
                </div>
                <div>
                    <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-orange-warm">System prompt</div>
                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $drawerLog->metadata['system_prompt'] ?? '' }}</pre>
                </div>
                <div class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-[11px] text-[#a8a8a8]">
                    <div><span class="text-[#8a8a8a]">model:</span> {{ $drawerLog->metadata['model'] ?? '-' }}</div>
                    <div><span class="text-[#8a8a8a]">max_turns:</span> {{ $drawerLog->metadata['max_turns'] ?? '-' }}</div>
                    <div><span class="text-[#8a8a8a]">max_budget_usd:</span> {{ $drawerLog->metadata['max_budget_usd'] ?? '-' }}</div>
                    <div><span class="text-[#8a8a8a]">resume_session_id:</span> {{ $drawerLog->metadata['resume_session_id'] ?? '-' }}</div>
                </div>
            @else
                @if($hasToolInput)
                    <div>
                        <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-green">
                            {{ ($drawerLog->metadata['tool'] ?? 'tool') === 'Bash' ? 'Command' : 'Input' }}
                        </div>
                        @if(($drawerLog->metadata['tool'] ?? null) === 'Bash' && isset($drawerLog->metadata['input']['command']))
                            <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#f5e9c9]">{{ $drawerLog->metadata['input']['command'] }}</pre>
                            @if(! empty($drawerLog->metadata['input']['description']))
                                <div class="mt-1 font-mono text-[11px] italic text-[#8a8a8a]"># {{ $drawerLog->metadata['input']['description'] }}</div>
                            @endif
                        @else
                            <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ json_encode($drawerLog->metadata['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </div>
                @endif
                @if($hasOutput)
                    <div>
                        @if($hasToolInput)
                            <div class="mb-1 text-[11px] font-medium uppercase tracking-wider text-yak-blue">Output</div>
                        @endif
                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $drawerLog->metadata['output'] }}</pre>
                    </div>
                @elseif(! $hasToolInput)
                    <div class="max-h-80 overflow-auto whitespace-pre-wrap break-words text-sm leading-relaxed text-[#d4d4d4]">{{ $drawerLog->message }}</div>
                @endif
            @endif
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
