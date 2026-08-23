<div wire:poll.{{ $this->pollInterval }}>
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
        ])
    </div>
</div>
