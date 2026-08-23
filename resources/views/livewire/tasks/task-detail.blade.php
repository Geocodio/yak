<div wire:poll.{{ $this->pollInterval }} x-data="{ detailsDrawerOpen: false }">
    @include('livewire.tasks.partials.header-band', [
        'task' => $task,
        'isActiveStatus' => $this->isActiveStatus(),
        'contextualAction' => $this->contextualAction,
        'outcomeButton' => $this->outcomeButton,
        'isResearchTask' => $this->isResearchTask(),
        'canReroute' => $this->canReroute,
        'rerouteOptions' => $this->rerouteOptions,
        'sourceUrl' => $this->sourceUrl,
        'nextSteps' => $this->nextSteps(),
        'detailedView' => $detailedView,
        'isAnsweredFix' => $this->isAnsweredFix,
        'deployment' => $this->deployment,
    ])

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
        <div class="min-w-0">
            {{-- Conversation thread --}}
            <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
                @foreach($this->thread as $i => $entry)
                    <div
                        wire:key="entry-{{ $i }}"
                        @if($entry->kind === 'user' && $entry->run) id="turn-{{ $entry->run->id }}" @endif
                    >
                        @include('livewire.tasks.partials.thread-entry', [
                            'entry' => $entry,
                            'i' => $i,
                            'thread' => $this->thread,
                            'task' => $task,
                            'detailedView' => $detailedView,
                            'expandedTurns' => $expandedTurns,
                            'clarificationTtl' => $this->clarificationTtl(),
                            'review' => $this->prReview,
                            'mediaByRun' => $this->mediaByRun,
                        ])
                    </div>
                @endforeach
            </div>

            @include('livewire.tasks.partials.composer', [
                'task' => $task,
                'composerText' => $composerText,
                'composerState' => $this->composerState,
                'head' => $this->task->conversation()->last() ?? $this->task,
            ])
        </div>

        @include('livewire.tasks.partials.sidebar', [
            'task' => $task,
            'visibleAttempt' => $visibleAttempt,
            'expandedGroups' => $expandedGroups,
            'logFilter' => $logFilter,
            'showDebug' => $showDebug,
            'keyPrefix' => '',
            'hiddenBelowLg' => true,
        ])
    </div>

    {{-- Mobile details drawer: same sidebar content as a slide-over, shown below `lg`. --}}
    <div class="lg:hidden">
        <div
            x-show="detailsDrawerOpen"
            x-cloak
            x-transition.opacity
            @click="detailsDrawerOpen = false"
            class="fixed inset-0 z-40 bg-black/30"
        ></div>

        <div
            x-show="detailsDrawerOpen"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @keydown.escape.window="detailsDrawerOpen = false"
            class="fixed inset-y-0 right-0 z-50 w-full max-w-sm overflow-y-auto bg-yak-cream p-4 shadow-2xl"
            data-testid="details-drawer-panel"
        >
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-medium text-yak-slate">Details</h2>
                <button
                    type="button"
                    @click="detailsDrawerOpen = false"
                    class="rounded-lg p-1 text-yak-blue hover:bg-[rgba(245,240,232,0.7)]"
                    aria-label="Close details"
                >
                    <flux:icon.x-mark class="!size-5" />
                </button>
            </div>

            @include('livewire.tasks.partials.sidebar', [
                'task' => $task,
                'visibleAttempt' => $visibleAttempt,
                'expandedGroups' => $expandedGroups,
                'logFilter' => $logFilter,
                'showDebug' => $showDebug,
                'keyPrefix' => 'drawer-',
                'hiddenBelowLg' => false,
            ])
        </div>
    </div>

    {{-- Modals rendered once, outside both sidebar instances --}}
    @include('livewire.tasks.partials.sidebar-modals', ['task' => $task])
</div>
