<div wire:poll.{{ $this->pollInterval }}>
    {{-- Breadcrumb --}}
    <div class="mb-6 text-sm">
        <a href="{{ route('tasks') }}" class="font-medium text-[#c4744a] hover:text-[#d4915e]">Tasks</a>
        <span class="text-[#6b8fa3]"> / </span>
        <span class="text-[#6b8fa3]">{{ $task->external_id ?? '#'.$task->id }}</span>
    </div>

    {{-- Section 1: Status Header (Glass Card) --}}
    <div class="mb-5 rounded-[28px] border border-white/60 bg-white/75 p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)] backdrop-blur-[40px] backdrop-saturate-[1.4]">
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-3.5">
                <span class="inline-flex items-center rounded-lg px-3 py-1 text-xs font-medium {{ \App\Livewire\Tasks\TaskList::statusBadgeClasses($task->status) }}">
                    @if($this->isActiveStatus())
                        <span class="mr-1.5 inline-block size-1.5 animate-pulse rounded-full bg-current"></span>
                    @endif
                    {{ str_replace('_', ' ', $task->status->value) }}
                </span>
                <span class="text-xs text-[#6b8fa3]">#{{ $task->id }}</span>
                @if($this->canRetry)
                    <flux:button variant="filled" size="sm" icon="arrow-path" wire:click="retry" wire:confirm="Re-queue this task?">Retry</flux:button>
                @endif
            </div>
            <h1 class="text-lg font-medium leading-snug text-[#3d4f5f]">{{ Str::before($task->description, "\n") }}</h1>
            @if($task->status === \App\Enums\TaskStatus::Failed && $task->error_log)
                <div class="mt-2 rounded-xl border border-[rgba(184,84,80,0.2)] bg-[rgba(184,84,80,0.06)] px-4 py-3">
                    <span class="text-xs font-medium uppercase tracking-wider text-[#b85450]">Error</span>
                    <p class="mt-1 text-sm leading-relaxed text-[#3d4f5f]">{{ $task->error_log }}</p>
                </div>
            @endif
            <div class="mt-1 flex flex-wrap gap-4">
                <span class="inline-flex items-center gap-1.5 text-xs text-[#6b8fa3]">
                    <flux:icon.wrench-screwdriver class="!size-3.5" />
                    <span class="font-medium">Mode:</span>
                    <span class="text-[#3d4f5f]">{{ ucfirst($task->mode->value) }}</span>
                </span>
                @if($task->source)
                    <span class="inline-flex items-center gap-1.5 text-xs text-[#6b8fa3]">
                        @if($task->source === 'slack')
                            <flux:icon.chat-bubble-left class="!size-3.5" />
                        @elseif($task->source === 'sentry')
                            <flux:icon.shield-exclamation class="!size-3.5" />
                        @elseif($task->source === 'linear')
                            <flux:icon.bolt class="!size-3.5" />
                        @else
                            <flux:icon.command-line class="!size-3.5" />
                        @endif
                        <span class="font-medium">Source:</span>
                        <span class="text-[#3d4f5f]">{{ ucfirst($task->source) }}</span>
                    </span>
                @endif
                @if($task->repo)
                    <span class="inline-flex items-center gap-1.5 text-xs text-[#6b8fa3]">
                        <flux:icon.code-bracket class="!size-3.5" />
                        <span class="font-medium">Repo:</span>
                        <span class="text-[#3d4f5f]">{{ $task->repo }}</span>
                    </span>
                @endif
                <span class="inline-flex items-center gap-1.5 text-xs text-[#6b8fa3]">
                    <flux:icon.clock class="!size-3.5" />
                    <span class="font-medium">Duration:</span>
                    <span class="text-[#3d4f5f]">{{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }}</span>
                </span>
                @if($task->attempts > 0)
                    <span class="inline-flex items-center gap-1.5 text-xs text-[#6b8fa3]">
                        <flux:icon.arrow-path class="!size-3.5" />
                        <span class="font-medium">Attempts:</span>
                        <span class="text-[#3d4f5f]">{{ $task->attempts }}</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Section 2: Description --}}
    <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
        <h2 class="mb-4 text-lg font-medium text-[#3d4f5f]">Description</h2>
        <div class="prose prose-sm prose-yak mb-4 max-w-none text-[#3d4f5f] prose-headings:text-[#3d4f5f] prose-a:text-[#c4744a] prose-a:hover:text-[#d4915e] prose-strong:text-[#3d4f5f] prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[#3d4f5f] prose-code:before:content-none prose-code:after:content-none">
            {!! Str::markdown($task->description) !!}
        </div>
        @if($task->context)
            <div class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Source context</div>
            <p class="mt-1 text-sm leading-relaxed text-[#6b8fa3]">{{ $task->context }}</p>
        @endif
    </div>

    {{-- Section 3: Clarification (if applicable) --}}
    @if($task->status === \App\Enums\TaskStatus::AwaitingClarification || $task->clarification_options)
        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            <h2 class="mb-4 text-lg font-medium text-[#3d4f5f]">Clarification</h2>
            @if($task->status === \App\Enums\TaskStatus::AwaitingClarification)
                <div class="mb-3 inline-flex items-center gap-2 rounded-lg bg-[rgba(212,145,94,0.12)] px-3 py-1.5 text-sm font-medium text-[#d4915e]">
                    <flux:icon.clock class="!size-4" />
                    Awaiting reply
                    @if($this->clarificationTtl())
                        <span class="text-xs font-normal">&mdash; {{ $this->clarificationTtl() }}</span>
                    @endif
                </div>
            @endif
            @if($task->clarification_options)
                <div class="mt-3">
                    <div class="mb-2 text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Options presented</div>
                    <ul class="space-y-1.5">
                        @foreach($task->clarification_options as $option)
                            <li class="flex items-start gap-2 text-sm text-[#3d4f5f]">
                                <span class="mt-1 inline-block size-1.5 shrink-0 rounded-full bg-[#c8b89a]"></span>
                                {{ $option }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- Section 5: Result Summary --}}
    @if($task->result_summary)
        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            <h2 class="mb-4 text-lg font-medium text-[#3d4f5f]">Result</h2>
            <div class="prose prose-sm prose-yak max-w-none text-[#3d4f5f] prose-headings:text-[#3d4f5f] prose-a:text-[#c4744a] prose-a:hover:text-[#d4915e] prose-strong:text-[#3d4f5f] prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[#3d4f5f] prose-code:before:content-none prose-code:after:content-none">
                {!! Str::markdown($task->result_summary) !!}
            </div>
            <div class="text-sm leading-loose text-[#3d4f5f]">
                @if($this->isFixTask() && $task->pr_url)
                    <div>
                        <strong>Pull Request:</strong>
                        <a href="{{ $task->pr_url }}" target="_blank" class="font-medium text-[#c4744a] hover:text-[#d4915e]" data-testid="pr-link">{{ $task->pr_url }}</a>
                    </div>
                @endif
                @if($this->isResearchTask() && $this->researchArtifact)
                    <div>
                        <strong>Research Findings:</strong>
                        <a href="{{ route('artifacts.viewer', ['task' => $task->id, 'filename' => $this->researchArtifact->filename]) }}" class="font-medium text-[#c4744a] hover:text-[#d4915e]" data-testid="research-link">View research artifact</a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Section 6: Screenshots --}}
    @if($this->screenshots->isNotEmpty() || $this->videos->isNotEmpty())
        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            <h2 class="mb-4 text-lg font-medium text-[#3d4f5f]">Media</h2>
            @if($this->screenshots->isNotEmpty())
                <div class="flex flex-wrap gap-5">
                    @foreach($this->screenshots as $screenshot)
                        <div>
                            <a href="{{ $screenshot->signedUrl() }}" target="_blank" class="block">
                                <img src="{{ $screenshot->signedUrl() }}" alt="{{ $screenshot->filename }}" class="h-[200px] w-[300px] rounded-[14px] border border-[rgba(200,184,154,0.4)] object-cover" loading="lazy" />
                            </a>
                            <div class="mt-2 text-center text-xs text-[#6b8fa3]">{{ $screenshot->filename }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if($this->videos->isNotEmpty())
                <div class="mt-4 space-y-3">
                    @foreach($this->videos as $video)
                        <div class="overflow-hidden rounded-[14px] border border-[rgba(200,184,154,0.4)]">
                            <video controls class="w-full max-w-xl">
                                <source src="{{ $video->signedUrl() }}" type="video/mp4">
                            </video>
                            <div class="bg-[#e8e0d2] px-3 py-2 text-xs text-[#6b8fa3]">{{ $video->filename }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Section 7: Debug Details (Collapsible) --}}
    <div class="mb-5 rounded-[14px] bg-[#e8e0d2] p-5">
        <button wire:click="toggleDebug" class="flex w-full items-center justify-between">
            <h2 class="text-lg font-medium text-[#3d4f5f]">Debug Details</h2>
            <flux:icon.chevron-down class="!size-4.5 text-[#6b8fa3] transition-transform duration-200 {{ $showDebug ? 'rotate-180' : '' }}" />
        </button>
        @if($showDebug)
            <div class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3.5">
                @if($task->session_id)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Session ID</span>
                        <span class="text-sm text-[#3d4f5f]"><code class="font-mono text-[13px]">{{ $task->session_id }}</code></span>
                    </div>
                @endif
                @if($task->model_used)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Model</span>
                        <span class="text-sm text-[#3d4f5f]">{{ $task->model_used }}</span>
                    </div>
                @endif
                @if($task->num_turns)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Turns</span>
                        <span class="text-sm text-[#3d4f5f]">{{ $task->num_turns }}</span>
                    </div>
                @endif
                <div class="flex flex-col gap-0.5">
                    <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Cost</span>
                    <span class="text-sm text-[#3d4f5f]">${{ number_format((float) $task->cost_usd, 2) }}</span>
                </div>
                @if($task->branch_name)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Branch</span>
                        <span class="text-sm text-[#3d4f5f]"><code class="font-mono text-[13px]">{{ $task->branch_name }}</code></span>
                    </div>
                @endif
                @if($task->started_at)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Started</span>
                        <span class="text-sm text-[#3d4f5f]">{{ $task->started_at->format('M j, Y g:i:s A') }}</span>
                    </div>
                @endif
                @if($task->completed_at)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Completed</span>
                        <span class="text-sm text-[#3d4f5f]">{{ $task->completed_at->format('M j, Y g:i:s A') }}</span>
                    </div>
                @endif
                @if($task->attempts > 0)
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Attempts</span>
                        <span class="text-sm text-[#3d4f5f]">{{ $task->attempts }}</span>
                    </div>
                @endif
            </div>
            @if($task->error_log)
                <div class="mt-4">
                    <span class="text-xs font-medium uppercase tracking-wider text-[#6b8fa3]">Error Log</span>
                    <pre class="mt-1 max-h-60 overflow-auto rounded-xl bg-[#2b3640] p-4 font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $task->error_log }}</pre>
                </div>
            @endif
        @endif
    </div>

    {{-- Section 8: Activity (Unified log with milestone highlighting) --}}
    @if($this->logs->isNotEmpty())
        <div class="mb-5 rounded-[28px] border border-[rgba(200,184,154,0.4)] bg-white p-7 shadow-[0_4px_6px_rgba(61,79,95,0.03),0_12px_24px_rgba(61,79,95,0.06)]">
            {{-- Milestone progress bar --}}
            <div class="mb-5 flex items-center gap-0" data-testid="milestone-stepper">
                @foreach($this->milestoneSteps as $stepIndex => $step)
                    <div class="flex items-center {{ $stepIndex < count($this->milestoneSteps) - 1 ? 'flex-1' : '' }}">
                        <div class="flex flex-col items-center gap-1">
                            <div class="flex size-6 items-center justify-center rounded-full text-[10px] font-semibold {{ $step['completed'] ? ($step['active'] ? 'bg-[#7a8c5e] text-white' : 'bg-[rgba(122,140,94,0.25)] text-[#7a8c5e]') : 'bg-[rgba(200,184,154,0.25)] text-[#c8b89a]' }}">
                                @if($step['completed'])
                                    <flux:icon.check class="!size-3.5" />
                                @else
                                    {{ $stepIndex + 1 }}
                                @endif
                            </div>
                            <span class="whitespace-nowrap text-[10px] font-medium {{ $step['completed'] ? ($step['active'] ? 'text-[#7a8c5e]' : 'text-[#6b8fa3]') : 'text-[#c8b89a]' }}">{{ $step['label'] }}</span>
                        </div>
                        @if($stepIndex < count($this->milestoneSteps) - 1)
                            <div class="mx-1 mb-4 h-0.5 flex-1 {{ $step['completed'] ? 'bg-[rgba(122,140,94,0.3)]' : 'bg-[rgba(200,184,154,0.2)]' }}"></div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-medium text-[#3d4f5f]">Activity</h2>
                <div class="flex items-center gap-3">
                    @if($this->isActiveStatus())
                        <button
                            x-data
                            @click="$dispatch('toggle-follow')"
                            x-text="$store.follow.active ? 'Following' : 'Follow'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-xs font-medium transition-colors"
                            :class="$store.follow.active ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]' : 'border-[rgba(200,184,154,0.4)] bg-white text-[#6b8fa3]'"
                            data-testid="follow-button"
                        >
                            <span class="size-1.5 rounded-full bg-current" :class="$store.follow.active && 'animate-pulse'"></span>
                        </button>
                    @endif
                    <span class="text-xs text-[#6b8fa3]">
                        {{ $this->logs->count() }} entries
                        @if($task->num_turns) &middot; {{ $task->num_turns }} turns @endif
                        @if($task->duration_ms) &middot; {{ \App\Livewire\Tasks\TaskList::formatDuration($task->duration_ms) }} @endif
                        @if($task->cost_usd) &middot; ${{ number_format((float) $task->cost_usd, 2) }} @endif
                    </span>
                </div>
            </div>

            {{-- Filter buttons --}}
            <div class="mb-4 flex gap-2" data-testid="log-filters">
                @foreach(['all' => 'All', 'actions' => 'Actions', 'milestones' => 'Milestones'] as $filterKey => $filterLabel)
                    <button
                        wire:click="setFilter('{{ $filterKey }}')"
                        class="rounded-lg border px-3 py-1 text-xs font-medium transition-colors {{ $logFilter === $filterKey ? 'border-[rgba(122,140,94,0.3)] bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]' : 'border-[rgba(200,184,154,0.4)] bg-white text-[#6b8fa3] hover:bg-[rgba(245,240,232,0.5)]' }}"
                        data-testid="filter-{{ $filterKey }}"
                    >
                        {{ $filterLabel }}
                    </button>
                @endforeach
            </div>

            <div
                x-data="{
                    init() {
                        Alpine.store('follow', { active: {{ $this->isActiveStatus() ? 'true' : 'false' }} });
                        this.scrollToBottom();
                    },
                    scrollToBottom() {
                        if (Alpine.store('follow').active) {
                            this.$nextTick(() => {
                                this.$refs.logList.scrollTo({ top: this.$refs.logList.scrollHeight, behavior: 'smooth' });
                            });
                        }
                    },
                    handleScroll() {
                        const el = this.$refs.logList;
                        const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 20;
                        if (!atBottom && Alpine.store('follow').active) {
                            Alpine.store('follow').active = false;
                        }
                    },
                    toggleFollow() {
                        Alpine.store('follow').active = !Alpine.store('follow').active;
                        if (Alpine.store('follow').active) {
                            this.scrollToBottom();
                        }
                    }
                }"
                x-ref="logList"
                x-on:toggle-follow.window="toggleFollow()"
                wire:poll.{{ $this->pollInterval }}="$refresh"
                x-effect="scrollToBottom()"
                class="max-h-[600px] overflow-y-auto"
                @scroll="handleScroll()"
            >
                @foreach($this->groupedLogs as $entry)
                    @if($entry['type'] === 'group')
                        {{-- Collapsed group of consecutive assistant entries --}}
                        @php
                            $groupIndex = $entry['groupIndex'];
                            $isGroupExpanded = $expandedGroups[$groupIndex] ?? false;
                            $lastLog = $entry['last'];
                        @endphp
                        <div
                            class="mb-2 overflow-hidden rounded-xl border border-[rgba(200,184,154,0.3)] bg-white"
                            wire:key="group-{{ $groupIndex }}"
                            data-testid="log-entry"
                        >
                            <button wire:click="toggleGroup({{ $groupIndex }})" class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)]">
                                <flux:icon.chevron-right class="!size-3.5 shrink-0 text-[#c8b89a] transition-transform duration-150 {{ $isGroupExpanded ? 'rotate-90' : '' }}" />
                                <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.1)] px-2 py-0.5 font-mono text-[11px] font-semibold text-[#6b8fa3]" data-testid="thinking-steps-badge">{{ $entry['count'] }} thinking steps</span>
                                <span class="min-w-0 flex-1 truncate text-[13px] italic text-[#6b8fa3]">{{ $lastLog->message }}</span>
                                <span class="shrink-0 font-mono text-[11px] text-[#c8b89a]">
                                    @if($this->isActiveStatus())
                                        {{ $lastLog->created_at->diffForHumans() }}
                                    @else
                                        {{ $lastLog->created_at->format('g:i:s A') }}
                                    @endif
                                </span>
                            </button>
                            @if($isGroupExpanded)
                                <div class="border-t border-[rgba(200,184,154,0.25)] bg-[rgba(245,240,232,0.3)]">
                                    @foreach($entry['logs'] as $gIdx => $groupLog)
                                        <div class="border-b border-[rgba(200,184,154,0.15)] px-4 py-2 last:border-b-0">
                                            <span class="text-[13px] italic text-[#6b8fa3]">{{ $groupLog->message }}</span>
                                            <span class="ml-2 font-mono text-[10px] text-[#c8b89a]">
                                                @if($this->isActiveStatus())
                                                    {{ $groupLog->created_at->diffForHumans() }}
                                                @else
                                                    {{ $groupLog->created_at->format('g:i:s A') }}
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- Single log entry --}}
                        @php
                            $log = $entry['log'];
                            $index = $entry['index'];
                            $logType = $log->metadata['type'] ?? null;
                            $isToolUse = $logType === 'tool_use';
                            $isAssistant = $logType === 'assistant';
                            $hasOutput = $isToolUse && isset($log->metadata['output']);
                            $isError = $log->metadata['is_error'] ?? false;
                            $hasExpandableContent = $hasOutput || $log->metadata;
                            $isMilestone = \App\Livewire\Tasks\TaskDetail::isMilestone($log);
                            // Auto-expand errors, auto-collapse assistant
                            $defaultExpanded = $isError && $hasOutput;
                            $isExpanded = $expandedLogs[$index] ?? $defaultExpanded;
                        @endphp
                        <div
                            class="mb-2 overflow-hidden rounded-xl border border-[rgba(200,184,154,0.3)] bg-white {{ $isMilestone ? 'border-l-[3px]' : '' }}"
                            @if($isMilestone) style="border-left-color: {{ \App\Livewire\Tasks\TaskDetail::logLevelColor($log->level) }};" @endif
                            wire:key="log-{{ $log->id }}"
                            data-testid="{{ $isMilestone ? 'milestone-log' : 'log-entry' }}"
                        >
                            <button wire:click="toggleLog({{ $index }})" class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-[rgba(245,240,232,0.5)] {{ $isMilestone ? 'bg-[rgba(245,240,232,0.3)]' : '' }}">
                                @if($hasExpandableContent && !$isMilestone)
                                    <flux:icon.chevron-right class="!size-3.5 shrink-0 text-[#c8b89a] transition-transform duration-150 {{ $isExpanded ? 'rotate-90' : '' }}" />
                                @else
                                    <span class="size-3.5 shrink-0"></span>
                                @endif
                                <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.1)] px-2 py-0.5 font-mono text-[11px] font-semibold text-[#6b8fa3]">{{ $index + 1 }}</span>
                                @if($isToolUse)
                                    <span class="shrink-0 rounded-md px-2 py-0.5 font-mono text-[11px] font-medium {{ $isError ? 'bg-[rgba(184,84,80,0.15)] text-[#b85450]' : 'bg-[rgba(122,140,94,0.15)] text-[#7a8c5e]' }}">
                                        {{ $log->metadata['tool'] ?? 'tool' }}
                                    </span>
                                @elseif(!$isAssistant)
                                    <span class="shrink-0 rounded-md px-2 py-0.5 font-mono text-[11px] font-medium {{ $log->level === 'error' ? 'bg-[rgba(184,84,80,0.15)] text-[#b85450]' : ($log->level === 'warning' ? 'bg-[rgba(212,145,94,0.15)] text-[#d4915e]' : 'bg-[rgba(143,179,196,0.15)] text-[#5a8da5]') }}">
                                        {{ $log->level }}
                                    </span>
                                @endif
                                <span class="min-w-0 flex-1 truncate text-[13px] {{ $isAssistant ? 'italic text-[#6b8fa3]' : 'text-[#3d4f5f]' }} {{ $isMilestone ? 'font-semibold' : '' }}">{{ $log->message }}</span>
                                @if($hasOutput)
                                    <span class="shrink-0 rounded-md bg-[rgba(107,143,163,0.08)] px-1.5 py-0.5 font-mono text-[10px] text-[#6b8fa3]">
                                        {{ $log->metadata['output_lines'] ?? '?' }} lines
                                    </span>
                                @endif
                                <span class="shrink-0 font-mono text-[11px] text-[#c8b89a]">
                                    @if($this->isActiveStatus())
                                        {{ $log->created_at->diffForHumans() }}
                                    @else
                                        {{ $log->created_at->format('g:i:s A') }}
                                    @endif
                                </span>
                            </button>
                            @if($isExpanded)
                                <div class="border-t border-[rgba(200,184,154,0.25)] bg-[#2b3640] p-4">
                                    @if($hasOutput)
                                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-[#d4d4d4]">{{ $log->metadata['output'] }}</pre>
                                    @else
                                        <div class="max-h-80 overflow-auto whitespace-pre-wrap break-words text-sm leading-relaxed text-[#d4d4d4]">{{ $log->message }}</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>
