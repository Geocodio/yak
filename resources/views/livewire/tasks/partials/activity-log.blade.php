{{--
    Sidebar activity log: run picker (+ per-run attempt sub-chips), filter
    buttons, and the grouped log list. Clicking a single log entry opens
    the log drawer (see partials/log-drawer.blade.php) instead of
    expanding inline; consecutive-assistant groups still expand inline.

    Expects: $task (App\Models\YakTask, root task), $visibleAttempt (int),
    $expandedGroups (array<int, bool>), $logFilter (string), $keyPrefix
    (string, default ''). $keyPrefix distinguishes wire:keys when this
    partial is rendered more than once per page (desktop sidebar + mobile
    drawer both include it via partials/sidebar.blade.php).
--}}
@php $keyPrefix = $keyPrefix ?? ''; @endphp
<div class="rounded-2xl border border-[rgba(200,184,154,0.4)] bg-white p-4" x-data="activityFollow()" data-testid="activity-log">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-xs font-medium uppercase tracking-wider text-yak-blue">Activity</h2>
        <span class="text-[11px] text-yak-tan">
            {{ $this->logs->count() }} entries
            @if($this->focusedRun->duration_ms) &middot; {{ \App\Livewire\Tasks\TaskList::formatDuration($this->focusedRun->duration_ms) }} @endif
        </span>
    </div>

    {{-- Run picker: one chip per run in the follow-up chain --}}
    @if($this->chainRuns->count() > 1)
        <div class="mb-3 flex flex-wrap gap-2" data-testid="run-picker">
            @foreach($this->chainRuns as $runIndex => $run)
                @php
                    $isFocusedRun = $this->focusedRun->is($run);
                    $isLiveRun = in_array($run->status, [
                        \App\Enums\TaskStatus::Running,
                        \App\Enums\TaskStatus::AwaitingClarification,
                        \App\Enums\TaskStatus::AwaitingCi,
                        \App\Enums\TaskStatus::Retrying,
                    ], true);
                @endphp
                <button
                    type="button"
                    wire:key="{{ $keyPrefix }}run-chip-{{ $run->id }}"
                    wire:click="focusRun({{ $run->id }})"
                    class="rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors {{ $isFocusedRun ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.5)]' }}"
                    data-testid="run-chip-{{ $run->id }}"
                >
                    Run {{ $runIndex + 1 }}@if($isLiveRun) <span class="text-yak-orange-warm">&middot; live</span>@endif
                </button>
            @endforeach
        </div>
    @endif

    {{-- Attempt selector (only shown when the focused run was retried) --}}
    @if(count($this->availableAttempts) > 0)
        <div class="mb-3 flex flex-wrap items-center gap-2" data-testid="attempt-selector">
            <span class="text-[11px] font-medium text-yak-tan">Attempt</span>
            @foreach($this->availableAttempts as $attempt)
                <button
                    wire:key="{{ $keyPrefix }}attempt-chip-{{ $attempt }}"
                    wire:click="selectAttempt({{ $attempt }})"
                    class="rounded-md border px-2 py-0.5 text-[11px] font-medium transition-colors {{ $visibleAttempt === $attempt ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.5)]' }}"
                    data-testid="attempt-{{ $attempt }}"
                >
                    #{{ $attempt }}@if($attempt === (int) $this->focusedRun->attempts) <span class="text-yak-tan">latest</span>@endif
                </button>
            @endforeach
        </div>
    @endif

    {{-- Filter buttons --}}
    <div class="mb-3 flex gap-1.5" data-testid="log-filters">
        @foreach(['all' => 'All', 'actions' => 'Actions', 'milestones' => 'Milestones'] as $filterKey => $filterLabel)
            <button
                wire:click="setFilter('{{ $filterKey }}')"
                class="rounded-md border px-2 py-1 text-[11px] font-medium transition-colors {{ $logFilter === $filterKey ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-yak-green' : 'border-[rgba(200,184,154,0.4)] bg-white text-yak-blue hover:bg-[rgba(245,240,232,0.5)]' }}"
                data-testid="filter-{{ $filterKey }}"
            >
                {{ $filterLabel }}
            </button>
        @endforeach
    </div>

    {{-- Free-text search over message, tool, command, and output --}}
    <div class="mb-3">
        <flux:input
            size="sm"
            icon="magnifying-glass"
            placeholder="Search this run…"
            wire:model.live.debounce.250ms="logSearch"
            data-testid="log-search"
            clearable
        />
    </div>

    <div class="relative">
        <div
            x-ref="logList"
            class="max-h-[420px] overflow-y-auto"
            @scroll.passive="onScroll()"
        >
        @if(count($this->groupedLogs) === 0 && trim($logSearch) !== '')
            <p class="px-3 py-6 text-center text-xs text-yak-tan" data-testid="log-search-empty">
                No entries match &ldquo;{{ $logSearch }}&rdquo;.
            </p>
        @endif
        @foreach($this->groupedLogs as $entry)
            @if($entry['type'] === 'group')
                {{-- Collapsed group of consecutive assistant entries --}}
                @php
                    $groupIndex = $entry['groupIndex'];
                    $isGroupExpanded = $expandedGroups[$groupIndex] ?? false;
                    $lastLog = $entry['last'];
                @endphp
                <div
                    class="mb-1.5 overflow-hidden rounded-lg border border-[rgba(200,184,154,0.3)] bg-white"
                    wire:key="{{ $keyPrefix }}group-{{ $groupIndex }}"
                    data-testid="log-entry"
                >
                    <button wire:click="toggleGroup({{ $groupIndex }})" class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)]">
                        <flux:icon.chevron-right class="!size-3 shrink-0 text-yak-tan transition-transform duration-150 {{ $isGroupExpanded ? 'rotate-90' : '' }}" />
                        <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.1)] px-1.5 py-0.5 font-mono text-[10px] font-semibold text-yak-blue" data-testid="thinking-steps-badge">{{ $entry['count'] }} thinking steps</span>
                        <span class="min-w-0 flex-1 truncate text-xs italic text-yak-blue">{{ \App\Support\Markdown::toPlainText($lastLog->message) }}</span>
                    </button>
                    @if($isGroupExpanded)
                        <div class="border-t border-[rgba(200,184,154,0.25)] bg-[rgba(245,240,232,0.3)]">
                            @foreach($entry['logs'] as $groupLog)
                                <div class="border-b border-[rgba(200,184,154,0.15)] px-3 py-1.5 last:border-b-0">
                                    <span class="text-xs italic text-yak-blue">{{ \App\Support\Markdown::toPlainText($groupLog->message) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                {{-- Single log entry: click opens the log drawer --}}
                @php
                    $log = $entry['log'];
                    $logType = $log->metadata['type'] ?? null;
                    $isToolUse = $logType === 'tool_use';
                    $isAssistant = $logType === 'assistant';
                    $isPrompt = $logType === 'prompt';
                    $hasOutput = $isToolUse && isset($log->metadata['output']);
                    $hasToolInput = $isToolUse && ! empty($log->metadata['input']);
                    $isError = $log->metadata['is_error'] ?? false;
                    $hasExpandableContent = $hasOutput || $hasToolInput || $isPrompt || $log->metadata;
                    $isMilestone = \App\Livewire\Tasks\TaskDetail::isMilestone($log);
                @endphp
                @php $isOpenInDrawer = $this->drawerLogId === $log->id; @endphp
                <div
                    class="mb-1.5 overflow-hidden rounded-lg border bg-white {{ $isOpenInDrawer ? 'border-yak-orange ring-1 ring-yak-orange/40' : 'border-[rgba(200,184,154,0.3)]' }}"
                    wire:key="{{ $keyPrefix }}log-{{ $log->id }}"
                    data-testid="{{ $isOpenInDrawer ? 'log-entry-open' : ($isMilestone ? 'milestone-log' : 'log-entry') }}"
                >
                    <button
                        @if($hasExpandableContent && !$isMilestone) wire:click="openLogDrawer({{ $log->id }})" @endif
                        class="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)] {{ $isMilestone ? 'bg-[rgba(245,240,232,0.3)]' : '' }}"
                    >
                        @if($isToolUse)
                            <span class="shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-medium {{ $isError ? 'bg-[rgba(184,84,80,0.15)] text-yak-danger' : 'bg-[rgba(122,140,94,0.15)] text-yak-green' }}">
                                {{ $log->metadata['tool'] ?? 'tool' }}
                            </span>
                        @elseif($isPrompt)
                            <span class="shrink-0 rounded-md bg-[rgba(212,145,94,0.15)] px-1.5 py-0.5 font-mono text-[10px] font-medium text-yak-orange-warm">
                                prompt
                            </span>
                        @elseif(!$isAssistant)
                            <span class="shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-medium {{ $log->level === 'error' ? 'bg-[rgba(184,84,80,0.15)] text-yak-danger' : ($log->level === 'warning' ? 'bg-[rgba(212,145,94,0.15)] text-yak-orange-warm' : 'bg-[rgba(143,179,196,0.15)] text-[#5a8da5]') }}">
                                {{ $log->level }}
                            </span>
                        @endif
                        <span class="min-w-0 flex-1 truncate text-xs {{ $isAssistant ? 'italic text-yak-blue' : 'text-yak-slate' }} {{ $isMilestone ? 'font-semibold' : '' }}">{{ \App\Support\Markdown::toPlainText($log->message) }}</span>
                        <span class="ml-auto shrink-0 font-mono text-[10px] text-yak-tan">
                            @if($this->isActiveStatus())
                                {{ $log->created_at->diffForHumans() }}
                            @else
                                {{ $log->created_at->format('g:i:s A') }}
                            @endif
                        </span>
                    </button>
                </div>
            @endif
        @endforeach
        </div>

        <button
            type="button"
            x-show="!following"
            x-cloak
            x-transition.opacity
            @click="jumpToLatest()"
            class="absolute bottom-3 right-3 inline-flex items-center gap-1.5 rounded-full bg-yak-orange px-3 py-1.5 text-xs font-medium text-white shadow-lg transition-colors hover:bg-yak-orange-warm"
            data-testid="jump-to-latest"
        >
            <flux:icon.arrow-down class="!size-3.5" />
            <span>Jump to latest</span>
        </button>
    </div>
</div>
