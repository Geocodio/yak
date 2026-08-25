{{--
    Sidebar activity log: run picker (+ per-run attempt sub-chips), and a
    preview strip of the transcript. Clicking a single entry opens the
    full transcript overlay (partials/transcript.blade.php) on that entry;
    consecutive-assistant groups still expand inline here. The filter and
    row markup is shared with the overlay via log-controls / log-rows.

    Expects: $task (App\Models\YakTask, root task), $visibleAttempt (int),
    $expandedGroups (array<int, bool>), $logFilter (string), $keyPrefix
    (string, default ''). $keyPrefix distinguishes wire:keys when this
    partial is rendered more than once per page (desktop sidebar + mobile
    drawer both include it via partials/sidebar.blade.php).
--}}
@php $keyPrefix = $keyPrefix ?? ''; @endphp
<div class="rounded-2xl border border-[rgba(200,184,154,0.4)] bg-white p-4" x-data="activityFollow()" data-testid="activity-log">
    <div class="mb-3 flex items-center gap-2">
        <h2 class="text-xs font-medium uppercase tracking-wider text-yak-blue">Activity</h2>
        <span class="ml-auto text-[11px] text-yak-tan">
            {{ $this->logs->count() }} entries
            @if($this->focusedRun->duration_ms) &middot; {{ \App\Livewire\Tasks\TaskList::formatDuration($this->focusedRun->duration_ms) }} @endif
        </span>
        @if($this->hasLogs)
            <button
                type="button"
                wire:click="openTranscriptCold"
                class="cursor-pointer rounded-md p-1 text-yak-blue transition-colors hover:bg-[rgba(245,240,232,0.7)] hover:text-yak-slate"
                title="Open the full transcript"
                data-testid="open-transcript"
            >
                <flux:icon.arrows-pointing-out class="!size-3.5" />
            </button>
        @endif
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

    @include('livewire.tasks.partials.log-controls')

    <div class="relative">
        <div
            x-ref="logList"
            class="max-h-[420px] overflow-y-auto"
            @scroll.passive="onScroll()"
        >
        @include('livewire.tasks.partials.log-rows')
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
