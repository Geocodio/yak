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
    ])

    {{-- Branch deployment preview (renders only when the task's branch has an active deployment) --}}
    <livewire:tasks.preview-widget :task="$task" />

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

            {{-- Video walkthrough (Reviewer Cut + Director's Cut) --}}
            @if($task->mode !== \App\Enums\TaskMode::Review)
                <livewire:tasks.video-walkthrough-card :task="$task" :key="'video-walkthrough-' . $task->id" />
            @endif

            {{-- Media --}}
            @if($this->screenshots->isNotEmpty() || $this->videos->isNotEmpty())
                <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-4 sm:p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
                    <h2 class="mb-4 text-lg font-medium text-yak-slate">Media</h2>
                    @if($this->screenshots->isNotEmpty())
                        <div class="flex flex-wrap gap-5">
                            @foreach($this->screenshots as $screenshot)
                                @php $screenshotUrl = $screenshot->signedUrl(); @endphp
                                <div>
                                    <a href="{{ $screenshotUrl }}" target="_blank" rel="noopener noreferrer" class="block">
                                        <img src="{{ $screenshotUrl }}" alt="{{ $screenshot->filename }}" class="h-[200px] w-[300px] rounded-[14px] border border-[rgba(200,184,154,0.4)] object-cover" loading="lazy" />
                                    </a>
                                    <div class="mt-2 text-center text-xs">
                                        <a href="{{ $screenshotUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">{{ $screenshot->filename }}</a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($this->videos->isNotEmpty())
                        <div class="mt-4 space-y-3">
                            @foreach($this->videos as $video)
                                @php $videoUrl = $video->signedUrl(); @endphp
                                <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]" wire:ignore>
                                    <video controls class="w-full max-w-xl">
                                        <source src="{{ $videoUrl }}" type="video/mp4">
                                    </video>
                                    <div class="bg-yak-cream-dark px-3 py-2 text-xs">
                                        <a href="{{ $videoUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-yak-orange hover:text-yak-orange-warm">{{ $video->filename }}</a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
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
