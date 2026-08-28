{{--
    Context sidebar: progress checklist (while in flight), run picker +
    activity log, and collapsed debug details.

    The log drawer and media lightbox modals live in
    partials/sidebar-modals.blade.php and are rendered once, outside this
    partial, since this partial itself may be rendered twice (desktop
    column + mobile drawer) and Livewire wire:keys must stay unique.

    The @foreach loops below don't carry explicit wire:key attributes —
    that's safe because each render of this partial sits under its own
    distinct ancestor element (the desktop column vs. the mobile drawer),
    so Livewire's positional diffing never has to reconcile nodes from one
    copy against the other.

    Expects: $task (App\Models\YakTask, root task), $visibleAttempt (int),
    $expandedGroups (array<int, bool>), $logFilter (string), $showDebug
    (bool), $keyPrefix (string, default ''), $hiddenBelowLg (bool, default
    true).
--}}
@php
    $keyPrefix = $keyPrefix ?? '';
    $hiddenBelowLg = $hiddenBelowLg ?? true;
@endphp
<div class="flex-col gap-4 {{ $hiddenBelowLg ? 'hidden lg:flex' : 'flex' }}" data-testid="task-sidebar">
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
            'keyPrefix' => $keyPrefix,
        ])
    @endif

    @include('livewire.tasks.partials.walkthrough-card')

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
                        <div class="truncate bg-yak-cream-dark px-2 py-1 text-[11px] text-yak-blue" title="{{ $artifact->caption ?? $artifact->filename }}">{{ $artifact->caption ?? $artifact->filename }}</div>
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

</div>
