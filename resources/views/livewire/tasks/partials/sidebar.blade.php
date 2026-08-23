{{--
    Context sidebar: progress checklist (while in flight), run picker +
    activity log, log drawer, and collapsed debug details.

    Expects: $task (App\Models\YakTask, root task), $visibleAttempt (int),
    $expandedGroups (array<int, bool>), $logFilter (string), $showDebug
    (bool).
--}}
<div class="flex flex-col gap-4" data-testid="task-sidebar">
    {{-- Progress checklist: only while the task is in flight --}}
    @if($this->isActiveStatus() || $task->status === \App\Enums\TaskStatus::Pending)
        <div class="rounded-2xl border border-[rgba(200,184,154,0.4)] bg-[rgba(232,224,210,0.45)] p-4" data-testid="progress-checklist">
            <h2 class="mb-3 text-xs font-medium uppercase tracking-wider text-yak-blue">Progress</h2>
            <ul class="flex flex-col gap-2">
                @foreach($this->milestoneSteps as $stepIndex => $step)
                    <flux:tooltip :content="$step['tooltip']">
                        <li class="flex items-center gap-2" data-testid="progress-step-{{ $stepIndex }}">
                            <div class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold {{ $step['completed'] ? ($step['active'] ? 'bg-yak-green text-white' : 'bg-[rgba(122,140,94,0.25)] text-yak-green') : 'bg-[rgba(200,184,154,0.25)] text-yak-tan' }}">
                                @if($step['completed'] && ! $step['active'])
                                    <flux:icon.check class="!size-3" />
                                @else
                                    {{ $stepIndex + 1 }}
                                @endif
                            </div>
                            <span class="text-xs font-medium {{ $step['completed'] ? ($step['active'] ? 'text-yak-green' : 'text-yak-blue') : 'text-yak-tan' }}">{{ $step['label'] }}</span>
                        </li>
                    </flux:tooltip>
                @endforeach
            </ul>
            <a
                href="{{ \App\Support\Docs::url('architecture.core-loop') }}"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-3 inline-flex items-center gap-1.5 text-xs text-yak-tan transition-colors hover:text-yak-slate"
                data-testid="milestone-docs-link"
            >
                <flux:icon.question-mark-circle class="!size-3.5" />
                Learn about the task lifecycle
            </a>
        </div>
    @endif

    {{-- Run picker + activity log --}}
    @if($this->hasLogs)
        @include('livewire.tasks.partials.activity-log', [
            'task' => $task,
            'visibleAttempt' => $visibleAttempt,
            'expandedGroups' => $expandedGroups,
            'logFilter' => $logFilter,
        ])
    @endif

    {{-- Latest media across the follow-up chain --}}
    @php $latestMedia = $this->latestMedia; @endphp
    @if($latestMedia['run'] !== null && $latestMedia['artifacts']->isNotEmpty())
        @php
            $latestRun = $latestMedia['run'];
            $isFromOtherRun = ! $latestRun->is($this->focusedRun);
        @endphp
        <div class="rounded-2xl border border-[rgba(200,184,154,0.4)] bg-white p-4" data-testid="latest-media">
            <h2 class="mb-1 text-xs font-medium uppercase tracking-wider text-yak-blue">Latest media</h2>
            @if($isFromOtherRun)
                <p class="mb-3 text-[11px] text-yak-tan" data-testid="latest-media-origin">
                    from run {{ $latestRun->id }} &middot; {{ $latestRun->completed_at?->diffForHumans() ?? $latestRun->created_at->diffForHumans() }}
                </p>
            @endif
            <div class="flex flex-wrap gap-3 {{ $isFromOtherRun ? '' : 'mt-3' }}">
                @foreach($latestMedia['artifacts'] as $artifact)
                    <button
                        type="button"
                        wire:click="openMediaLightbox({{ $artifact->id }})"
                        class="block w-[140px] shrink-0 overflow-hidden rounded-[10px] border border-[rgba(200,184,154,0.45)] text-left transition-shadow hover:shadow-[0_4px_10px_rgba(61,79,95,0.08)]"
                        data-testid="latest-media-thumb-{{ $artifact->id }}"
                    >
                        @if($artifact->type === 'video')
                            <video muted preload="metadata" class="h-[90px] w-full bg-yak-cream-dark object-cover" src="{{ $artifact->signedUrl() }}"></video>
                        @else
                            <img src="{{ $artifact->signedUrl() }}" alt="{{ $artifact->filename }}" loading="lazy" class="h-[90px] w-full object-cover" />
                        @endif
                        <div class="truncate bg-yak-cream-dark px-2 py-1 text-[11px] text-yak-blue">{{ $artifact->filename }}</div>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Debug details (collapsible), scoped to the focused run --}}
    @php $run = $this->focusedRun; @endphp
    <div class="rounded-2xl bg-[rgba(232,224,210,0.45)] p-4">
        <button wire:click="toggleDebug" class="flex w-full items-center justify-between">
            <h2 class="text-xs font-medium uppercase tracking-wider text-yak-blue">Debug Details</h2>
            <flux:icon.chevron-down class="!size-4 text-yak-blue transition-transform duration-200 {{ $showDebug ? 'rotate-180' : '' }}" />
        </button>
        @if($showDebug)
            <div class="mt-3 grid grid-cols-1 gap-3">
                @if($run->session_id)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Session ID</span>
                        <span class="text-xs text-yak-slate"><code class="font-mono text-[11px]">{{ $run->session_id }}</code></span>
                    </div>
                @endif
                @if($run->model_used)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Model</span>
                        <span class="text-xs text-yak-slate">{{ $run->model_used }}</span>
                    </div>
                @endif
                @if($run->num_turns)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Turns</span>
                        <span class="text-xs text-yak-slate">{{ $run->num_turns }}</span>
                    </div>
                @endif
                <div class="flex flex-col gap-0.5" title="List-price token cost reported by Claude Code. Covered by subscription — not billed.">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Claude Code cost (est.)</span>
                    <span class="text-xs text-yak-slate">${{ number_format((float) $run->cost_usd, 2) }}</span>
                </div>
                <div class="flex flex-col gap-0.5" title="Actual Anthropic API usage for this task (notification copy, routing).">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">API-billed spend</span>
                    <span class="text-xs text-yak-slate" data-testid="api-spend">${{ number_format($this->apiSpendUsd, 4) }}</span>
                </div>
                @if($run->branch_name && ! $this->isAnsweredFix)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Branch</span>
                        <span class="text-xs text-yak-slate"><code class="font-mono text-[11px]">{{ $run->branch_name }}</code></span>
                    </div>
                @endif
                @if($run->started_at)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Started</span>
                        <span class="text-xs text-yak-slate">{{ $run->started_at->format('M j, Y g:i:s A') }}</span>
                    </div>
                @endif
                @if($run->completed_at)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Completed</span>
                        <span class="text-xs text-yak-slate">{{ $run->completed_at->format('M j, Y g:i:s A') }}</span>
                    </div>
                @endif
                @if($run->attempts > 0)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Attempts</span>
                        <span class="text-xs text-yak-slate">{{ $run->attempts }}</span>
                    </div>
                @endif
            </div>
            @if($run->error_log)
                <div class="mt-3">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-yak-blue">Error Log</span>
                    <pre class="mt-1 max-h-60 overflow-auto rounded-xl bg-[#2b3640] p-3 font-mono text-[11px] leading-relaxed text-[#d4d4d4]">{{ $run->error_log }}</pre>
                </div>
            @endif
        @endif
    </div>

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
</div>
