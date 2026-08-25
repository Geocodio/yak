{{--
    Filter buttons and the free-text search box for the activity log.
    Shared by the sidebar card and the transcript overlay so the two
    surfaces always filter the same way.

    Expects: $logFilter (string), $logSearch (string).
--}}
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
